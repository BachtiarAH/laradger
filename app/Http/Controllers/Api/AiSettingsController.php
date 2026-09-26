<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TestAiSettingsRequest;
use App\Http\Requests\UpdateAiSettingsRequest;
use App\Http\Resources\AiSettingsResource;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiSettingsController extends Controller
{
    public function __construct(
        private readonly AiSettingsService $settings,
    ) {}

    public function show(Request $request): AiSettingsResource
    {
        return new AiSettingsResource($this->settings->settingsFor($request->user()));
    }

    public function update(UpdateAiSettingsRequest $request): AiSettingsResource
    {
        $user = $request->user();

        $this->settings->update($user, $request->validated());

        return new AiSettingsResource($this->settings->settingsFor($user->refresh()));
    }

    public function destroy(Request $request): AiSettingsResource
    {
        $user = $request->user();

        $this->settings->clear($user);

        return new AiSettingsResource($this->settings->settingsFor($user->refresh()));
    }

    public function test(TestAiSettingsRequest $request): JsonResponse
    {
        try {
            $result = $this->settings->test($request->user(), $request->validated());
        } catch (AiProviderException $e) {
            return response()->json([
                'message' => 'The AI connection test failed.',
                'errors' => [
                    'api_key' => [$e->getMessage()],
                ],
            ], 422);
        }

        return response()->json(['data' => $result]);
    }
}
