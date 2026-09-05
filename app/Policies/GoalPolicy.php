<?php

namespace App\Policies;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GoalPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Goal $goal): bool
    {
        return $user->belongsToTenant($goal->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Goal $goal): bool
    {
        return $user->belongsToTenant($goal->tenant_id);
    }

    public function delete(User $user, Goal $goal): bool
    {
        return $user->belongsToTenant($goal->tenant_id);
    }
}
