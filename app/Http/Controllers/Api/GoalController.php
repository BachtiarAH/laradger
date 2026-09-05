<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGoalRequest;
use App\Http\Requests\UpdateGoalRequest;
use App\Http\Resources\GoalResource;
use App\Models\AuditLog;
use App\Models\Goal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class GoalController extends Controller
{
    public function index(string $tenant, Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Goal::class);

        $goals = Goal::query()
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderBy('name')
            ->paginate();

        return GoalResource::collection($goals);
    }

    public function store(string $tenant, StoreGoalRequest $request): JsonResponse
    {
        $goal = DB::transaction(function () use ($request) {
            $goal = Goal::create($request->validated());

            $this->logAudit($goal, 'goal.created', after: $goal->toArray());

            return $goal;
        });

        return (new GoalResource($goal))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $tenant, Goal $goal): GoalResource
    {
        $this->authorize('view', $goal);

        return new GoalResource($goal);
    }

    public function update(string $tenant, UpdateGoalRequest $request, Goal $goal): GoalResource
    {
        $this->authorize('update', $goal);

        DB::transaction(function () use ($request, $goal) {
            $before = $goal->toArray();
            $goal->update($request->validated());
            $this->logAudit($goal, 'goal.updated', before: $before, after: $goal->fresh()->toArray());
        });

        return new GoalResource($goal->fresh());
    }

    public function destroy(string $tenant, Goal $goal): JsonResponse
    {
        $this->authorize('delete', $goal);

        DB::transaction(function () use ($goal) {
            $before = $goal->toArray();
            $goal->delete();
            $this->logAudit($goal, 'goal.deleted', before: $before);
        });

        return response()->json(null, 204);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function logAudit(Goal $goal, string $action, ?array $before = null, ?array $after = null): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $action,
        ]);
    }
}
