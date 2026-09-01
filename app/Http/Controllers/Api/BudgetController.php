<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class BudgetController extends Controller
{
    public function index(string $tenant, Request $request): AnonymousResourceCollection
    {
        $query = Budget::query()
            ->with(['accounts', 'tags']);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        if ($request->filled('starts_at')) {
            $query->whereDate('starts_at', '>=', $request->date('starts_at'));
        }

        if ($request->filled('ends_at')) {
            $query->whereDate('ends_at', '<=', $request->date('ends_at'));
        }

        if ($request->filled('tag_id')) {
            $query->whereHas('tags', fn ($tags) => $tags->whereKey($request->input('tag_id')));
        }

        if ($request->filled('account_id')) {
            $query->whereHas('accounts', fn ($accounts) => $accounts->whereKey($request->input('account_id')));
        }

        return BudgetResource::collection($query->latest('starts_at')->paginate());
    }

    public function store(string $tenant, StoreBudgetRequest $request): JsonResponse
    {
        $data = $request->validated();
        $accountIds = $data['account_ids'] ?? [];
        $tagIds = $data['tag_ids'] ?? [];
        unset($data['account_ids'], $data['tag_ids']);

        $budget = DB::transaction(function () use ($request, $data, $accountIds, $tagIds) {
            $budget = Budget::create([
                ...$data,
                'user_id' => $request->user()->id,
            ]);
            $budget->accounts()->sync($accountIds);
            $budget->tags()->sync($tagIds);

            return $budget;
        });

        return (new BudgetResource($budget->load(['accounts', 'tags'])))->response()->setStatusCode(201);
    }

    public function show(string $tenant, Request $request, Budget $budget): BudgetResource
    {
        $this->ensureOwner($request, $budget);

        return new BudgetResource($budget->load(['accounts', 'tags']));
    }

    public function update(string $tenant, UpdateBudgetRequest $request, Budget $budget): BudgetResource
    {
        $this->ensureOwner($request, $budget);

        $data = $request->validated();
        $accountIds = array_key_exists('account_ids', $data) ? $data['account_ids'] : null;
        $tagIds = array_key_exists('tag_ids', $data) ? $data['tag_ids'] : null;
        unset($data['account_ids'], $data['tag_ids']);

        DB::transaction(function () use ($budget, $data, $accountIds, $tagIds) {
            $budget->update($data);

            if ($accountIds !== null) {
                $budget->accounts()->sync($accountIds);
            }

            if ($tagIds !== null) {
                $budget->tags()->sync($tagIds);
            }
        });

        return new BudgetResource($budget->fresh()->load(['accounts', 'tags']));
    }

    public function destroy(string $tenant, Request $request, Budget $budget): JsonResponse
    {
        $this->ensureOwner($request, $budget);
        $budget->delete();

        return response()->json(null, 204);
    }

    private function ensureOwner(Request $request, Budget $budget): void
    {
        abort_unless($request->user()->belongsToTenant($budget->tenant_id), 404);
    }
}
