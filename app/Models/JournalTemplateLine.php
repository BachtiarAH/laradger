<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Database\Factories\JournalTemplateLineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalTemplateLine extends Model
{
    /** @use HasFactory<JournalTemplateLineFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (TenantContext::hasTenant()) {
                $builder->whereHas('journalTemplate', fn ($query) => $query->where('tenant_id', TenantContext::id()));
            } elseif (TenantContext::isSystemContext()) {
                // Explicit system context — no tenant filter.
            } else {
                $builder->whereRaw('1 = 0');
            }
        });
    }

    protected $fillable = [
        'journal_template_id',
        'account_id',
        'line_number',
        'debit',
        'credit',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function journalTemplate(): BelongsTo
    {
        return $this->belongsTo(JournalTemplate::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
