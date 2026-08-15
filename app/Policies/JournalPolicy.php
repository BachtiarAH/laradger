<?php

namespace App\Policies;

use App\Models\Journal;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class JournalPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Journal $journal): bool
    {
        return $user->belongsToTenant($journal->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Journal $journal): bool
    {
        return $user->belongsToTenant($journal->tenant_id)
            && $journal->status === 'draft';
    }

    public function delete(User $user, Journal $journal): bool
    {
        return $user->belongsToTenant($journal->tenant_id)
            && $journal->status === 'draft';
    }

    public function reverse(User $user, Journal $journal): bool
    {
        return $user->belongsToTenant($journal->tenant_id)
            && $journal->status === 'posted';
    }

    public function restore(User $user, Journal $journal): bool
    {
        return $user->belongsToTenant($journal->tenant_id)
            && $journal->status === 'draft';
    }

    public function forceDelete(User $user, Journal $journal): bool
    {
        return false;
    }
}
