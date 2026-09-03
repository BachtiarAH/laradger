<?php

namespace App\Policies;

use App\Models\JournalTemplate;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class JournalTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JournalTemplate $template): bool
    {
        return $user->belongsToTenant($template->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, JournalTemplate $template): bool
    {
        return $user->belongsToTenant($template->tenant_id);
    }

    public function delete(User $user, JournalTemplate $template): bool
    {
        return $user->belongsToTenant($template->tenant_id);
    }

    public function generate(User $user, JournalTemplate $template): bool
    {
        return $user->belongsToTenant($template->tenant_id);
    }
}
