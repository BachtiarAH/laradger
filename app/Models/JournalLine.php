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
