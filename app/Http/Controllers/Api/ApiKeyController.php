<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Services\ApiKeys\ApiKeyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * A user's own API keys.
 *
 * Lives outside the `ability` middleware group on purpose. These routes manage
 * credentials rather than ledger data, so the ledger tiers do not describe them
 * — and `platform:admin` is not a ledger ability at all, so gating them would
 * lock an admin key out of managing keys while letting a read-only key mint one.
 * The rules that do apply are enforced here.
 */
class ApiKeyController extends Controller
{
    public function __construct(private readonly ApiKeyManager $keys) {}

    /**
     * List the caller's keys.
     *
     * Reachable with a key as well as from the app: an agent should be able to
     * see which credentials exist for the account it runs under.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ApiKeyResource::collection($this->keys->forUser($request->user()));
    }

    /**
     * Mint a key. Issued keys are refused; a person is not.
     *
     * The refusal is the load-bearing part. A bearer key that could issue
     * another key could issue one wider than itself, so a leaked `ledger:read`
     * key would be a path to full account takeover — the ability hierarchy would
     * stop meaning anything. Revoking and listing stay open to keys for the
     * opposite reason: a leaked key should be killable without a human present.
     */
    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        if (! $this->keys->canIssue($request->user()->currentAccessToken())) {
            abort(403, 'API keys can only be issued from a signed-in account. An API key cannot create another key.');
        }

        $validated = $request->validated();

        try {
            $token = $this->keys->create(
                $request->user(),
                $validated['abilities'],
                $validated['days'] ?? null,
                $validated['label'] ?? null,
            );
        } catch (RuntimeException $exception) {
            // The request rules already reject unknown abilities and out-of-range
            // lifetimes, so reaching this means the issuer and the FormRequest
            // disagree. Surfacing it as a field error beats a 500.
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['abilities' => [$exception->getMessage()]],
            ], 422);
        }

        return response()->json([
            'data' => [
                'key' => (new ApiKeyResource($token->accessToken))->toArray($request),
                // Shown once. Only the hash is stored, so this cannot be
                // recovered — a lost key is replaced, never looked up.
                'plain_text_token' => $token->plainTextToken,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $this->keys->revoke($request->user(), $token);

        return response()->json(status: 204);
    }
}
