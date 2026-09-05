<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Database\Factories\JournalLineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class JournalLine extends Model
{
    /** @use HasFactory<JournalLineFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (TenantContext::hasTenant()) {
                $builder->whereHas('journal', fn ($query) => $query->where('tenant_id', TenantContext::id()));
            }
        });

        static::saving(function (JournalLine $line): void {
            // Immutability: existing lines on posted/archived journals are locked.
            // Creating lines is allowed so that a posted journal can be created
            // atomically with its lines; later additions are blocked at the
            // controller/policy layer (JournalPolicy/JournalLinePolicy → 403).
            if ($line->exists) {
                $journal = Journal::withoutGlobalScopes()->find($line->getOriginal('journal_id') ?? $line->journal_id);

                if ($journal && $journal->status !== 'draft') {
                    throw ValidationException::withMessages([
                        'journal_id' => 'Lines can only be modified on draft journals. Posted journals are immutable — use reversal instead.',
                    ]);
                }

                if ($line->isDirty('account_id') || $line->isDirty('debit') || $line->isDirty('credit') || $line->isDirty('journal_id')) {
                    $currentJournal = $line->journal_id ? Journal::withoutGlobalScopes()->find($line->journal_id) : $journal;

                    if (($currentJournal && $currentJournal->status !== 'draft') || ($journal && $journal->status !== 'draft')) {
                        throw ValidationException::withMessages([
                            'journal_id' => 'Amount, account, and line changes are blocked for posted journals. Use reversal + correction journal.',
                        ]);
                    }
                }
            }

            if ($line->account_id) {
                $account = Account::withoutGlobalScopes()->whereKey($line->account_id)->first();
                if ($account && (bool) $account->is_header) {
                    $name = $account->name ?: $account->code;
                    $msg = 'Akun "'.$name.'" adalah akun induk — tidak bisa dipakai transaksi.';
                    throw ValidationException::withMessages([
                        'account_id' => $msg,
                    ]);
                }
            }
        });

        static::deleting(function (JournalLine $line): void {
            $journal = Journal::withoutGlobalScopes()->find($line->journal_id);

            if ($journal && $journal->status !== 'draft') {
                throw ValidationException::withMessages([
                    'journal_id' => 'Lines can only be deleted from draft journals. Posted journals are immutable — use reversal instead.',
                ]);
            }
        });
    }

    protected $fillable = [
        'journal_id',
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

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
