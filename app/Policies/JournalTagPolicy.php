<?php

namespace App\Policies;

use App\Models\JournalTag;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class JournalTagPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JournalTag $journalTag): bool
    {
        return $journalTag->journal?->tenant_id
            && $user->belongsToTenant($journalTag->journal->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, JournalTag $journalTag): bool
    {
        return $journalTag->journal?->tenant_id
            && $user->belongsToTenant($journalTag->journal->tenant_id);
    }

    public function delete(User $user, JournalTag $journalTag): bool
    {
        return $journalTag->journal?->tenant_id
            && $user->belongsToTenant($journalTag->journal->tenant_id);
    }

    public function restore(User $user, JournalTag $journalTag): bool
    {
        return $journalTag->journal?->tenant_id
            && $user->belongsToTenant($journalTag->journal->tenant_id);
    }

    public function forceDelete(User $user, JournalTag $journalTag): bool
    {
        return false;
    }
}
