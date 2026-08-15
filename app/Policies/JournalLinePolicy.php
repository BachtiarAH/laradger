<?php

namespace App\Policies;

use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class JournalLinePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JournalLine $journalLine): bool
    {
        return $journalLine->journal?->tenant_id
            && $user->belongsToTenant($journalLine->journal->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, JournalLine $journalLine): bool
    {
        return $journalLine->journal?->tenant_id
            && $user->belongsToTenant($journalLine->journal->tenant_id)
            && $journalLine->journal?->status === 'draft';
    }

    public function delete(User $user, JournalLine $journalLine): bool
    {
        return $journalLine->journal?->tenant_id
            && $user->belongsToTenant($journalLine->journal->tenant_id)
            && $journalLine->journal?->status === 'draft';
    }

    public function restore(User $user, JournalLine $journalLine): bool
    {
        return $journalLine->journal?->tenant_id
            && $user->belongsToTenant($journalLine->journal->tenant_id)
            && $journalLine->journal?->status === 'draft';
    }

    public function forceDelete(User $user, JournalLine $journalLine): bool
    {
        return false;
    }
}
