<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AccountPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Account $account): bool
    {
        return $user->belongsToTenant($account->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Account $account): bool
    {
        return $user->belongsToTenant($account->tenant_id);
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->belongsToTenant($account->tenant_id);
    }

    public function restore(User $user, Account $account): bool
    {
        return $user->belongsToTenant($account->tenant_id);
    }

    public function forceDelete(User $user, Account $account): bool
    {
        return false;
    }
}
