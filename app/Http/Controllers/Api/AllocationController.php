<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AllocateAllocationRequest;
use App\Http\Requests\ReleaseAllocationRequest;
use App\Http\Requests\StoreAllocationRequest;
use App\Http\Requests\UpdateAllocationRequest;
use App\Http\Resources\AllocationResource;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Services\AllocationAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AllocationController extends Controller
{
    public function __construct(
        private readonly AllocationAdjustmentService $adjustments,
    ) {}

    public function index(string $tenant, Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Allocation::class);

        $allocations = Allocation::with('accounts')
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')
            ->paginate();

        return AllocationResource::collection($allocations);
    }

    public function store(string $tenant, StoreAllocationRequest $request): JsonResponse
    {
        $allocation = DB::transaction(function () use ($request) {
            $allocation = Allocation::create($request->validated());

            $this->logAudit($allocation, 'allocation.created', after: $this->snapshot($allocation));

            return $allocation;
        });

        return (new AllocationResource($allocation->load('accounts')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $tenant, Allocation $allocation): AllocationResource
    {
        $this->authorize('view', $allocation);

        return new AllocationResource($allocation->load('accounts'));
    }

    public function update(string $tenant, UpdateAllocationRequest $request, Allocation $allocation): AllocationResource
    {
        $this->authorize('update', $allocation);

        DB::transaction(function () use ($request, $allocation) {
            $before = $this->snapshot($allocation);

            $allocation->update($request->validated());

            $this->logAudit($allocation, 'allocation.updated', before: $before, after: $this->snapshot($allocation->fresh()));
        });

        return new AllocationResource($allocation->fresh('accounts'));
    }

    public function destroy(string $tenant, Allocation $allocation): JsonResponse
    {
        $this->authorize('delete', $allocation);

        DB::transaction(function () use ($allocation) {
            $before = $this->snapshot($allocation->load('accounts'));

            $allocation->delete();

            $this->logAudit($allocation, 'allocation.deleted', before: $before);
        });

        return response()->json(null, 204);
    }

    public function allocate(string $tenant, AllocateAllocationRequest $request, Allocation $allocation): AllocationResource
    {
        $this->authorize('allocate', $allocation);

        DB::transaction(function () use ($request, $allocation) {
            $account = Account::query()->whereKey($request->input('account_id'))->firstOrFail();

            $this->adjustments->allocate($allocation, $account, (float) $request->float('amount'));
        });

        return new AllocationResource($allocation->fresh('accounts'));
    }

    public function release(string $tenant, ReleaseAllocationRequest $request, Allocation $allocation): AllocationResource
    {
        $this->authorize('release', $allocation);

        DB::transaction(function () use ($request, $allocation) {
            $account = Account::query()->whereKey($request->input('account_id'))->firstOrFail();

            $this->adjustments->release($allocation, $account, (float) $request->float('amount'));
        });

        return new AllocationResource($allocation->fresh('accounts'));
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function logAudit(Allocation $allocation, string $action, ?array $before = null, ?array $after = null): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $action,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Allocation $allocation): array
    {
        $snapshot = [
            'id' => $allocation->id,
            'name' => $allocation->name,
            'description' => $allocation->description,
            'target_amount' => $allocation->target_amount !== null ? number_format((float) $allocation->target_amount, 2, '.', '') : null,
        ];

        if ($allocation->relationLoaded('accounts')) {
            $snapshot['accounts'] = $allocation->accounts->map(fn ($account) => [
                'account_id' => $account->id,
                'amount' => number_format((float) $account->pivot->amount, 2, '.', ''),
            ])->values()->all();
        }

        return $snapshot;
    }
}
