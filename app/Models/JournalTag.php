<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Database\Factories\JournalTagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalTag extends Model
{
    /** @use HasFactory<JournalTagFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (TenantContext::hasTenant()) {
                $builder->whereHas('journal', fn ($query) => $query->where('tenant_id', TenantContext::id()));
            } elseif (TenantContext::isSystemContext()) {
                // Explicit system context — no tenant filter.
            } else {
                $builder->whereRaw('1 = 0');
            }
        });
    }

    protected $fillable = [
        'journal_id',
        'tag_id',
    ];

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    public function tag()
    {
        return $this->belongsTo(Tag::class);
    }
}
