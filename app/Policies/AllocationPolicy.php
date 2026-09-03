<?php

namespace App\Policies;

use App\Models\Allocation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AllocationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Allocation $allocation): bool
    {
        return $user->belongsToTenant($allocation->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Allocation $allocation): bool
    {
        return $user->belongsToTenant($allocation->tenant_id);
    }

    public function delete(User $user, Allocation $allocation): bool
    {
        return $user->belongsToTenant($allocation->tenant_id);
    }

    public function allocate(User $user, Allocation $allocation): bool
    {
        return $user->belongsToTenant($allocation->tenant_id);
    }

    public function release(User $user, Allocation $allocation): bool
    {
        return $user->belongsToTenant($allocation->tenant_id);
    }
}
