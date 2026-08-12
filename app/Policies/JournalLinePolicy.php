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
        return $journalLine->journal?->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, JournalLine $journalLine): bool
    {
        return $journalLine->journal?->user_id === $user->id
            && $journalLine->journal?->status === 'draft';
    }

    public function delete(User $user, JournalLine $journalLine): bool
    {
        return $journalLine->journal?->user_id === $user->id
            && $journalLine->journal?->status === 'draft';
    }

    public function restore(User $user, JournalLine $journalLine): bool
    {
        return $journalLine->journal?->user_id === $user->id
            && $journalLine->journal?->status === 'draft';
    }

    public function forceDelete(User $user, JournalLine $journalLine): bool
    {
        return false;
    }
}
