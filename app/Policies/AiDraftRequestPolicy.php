<?php

namespace App\Policies;

use App\Models\AiDraftRequest;
use App\Models\User;

class AiDraftRequestPolicy
{
    /**
     * AI draft requests are personal — only the user who submitted them may
     * cancel them.
     */
    public function update(User $user, AiDraftRequest $draftRequest): bool
    {
        return $draftRequest->user_id === $user->getKey();
    }
}
