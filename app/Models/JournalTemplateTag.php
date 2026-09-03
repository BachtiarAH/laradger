<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalTemplateTag extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (TenantContext::hasTenant()) {
                $builder->whereHas('journalTemplate', fn ($query) => $query->where('tenant_id', TenantContext::id()));
            }
        });
    }

    public function journalTemplate(): BelongsTo
    {
        return $this->belongsTo(JournalTemplate::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
