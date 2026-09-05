<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Tenancy\TenantContext;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    public const TYPE_CODE_PREFIXES = [
        'asset' => 'AS',
        'liability' => 'LI',
        'equity' => 'EQ',
        'income' => 'IN',
        'expense' => 'EX',
    ];

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'type',
        'is_header',
        'parent_id',
        'currency',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_header' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Account $account): void {
            if (! $account->code) {
                $account->code = self::generateCode($account->type);
            }
        });

        static::saving(function (Account $account): void {
            // Normalize default.
            if (! array_key_exists('is_header', $account->getAttributes()) || $account->is_header === null) {
                $account->is_header = false;
            }

            $isHeader = (bool) $account->is_header;

            // Header accounts must not have journal lines; leaf accounts must not have children.
            if ($account->exists) {
                if ($isHeader && $account->journalLines()->exists()) {
                    throw ValidationException::withMessages([
                        'is_header' => 'Akun ini sudah memiliki transaksi dan tidak bisa diubah menjadi akun induk. Pindahkan transaksi ke sub-akun terlebih dahulu.',
                    ]);
                }

                if (! $isHeader && $account->children()->exists()) {
                    throw ValidationException::withMessages([
                        'is_header' => 'Akun ini memiliki sub-akun dan tidak bisa diubah menjadi akun detail. Hapus atau pindahkan sub-akun terlebih dahulu.',
                    ]);
                }
            }

            // If a parent is set, the parent must be a header and must not have journal lines.
            if ($account->parent_id) {
                // Avoid self-reference.
                if ($account->exists && $account->parent_id === $account->id) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'An account cannot be its own parent.',
                    ]);
                }

                $parent = self::withoutGlobalScopes()->whereKey($account->parent_id)->first();
                if ($parent) {
                    if ($account->tenant_id && $parent->tenant_id !== $account->tenant_id) {
                        throw ValidationException::withMessages([
                            'parent_id' => 'Parent account must belong to the same tenant.',
                        ]);
                    }

                    // A detail account that has no journal lines yet is promoted to
                    // an induk (kategori) automatically the moment it gains children,
                    // so users do not have to flip the header flag manually first.
                    if (! $parent->is_header && ! $parent->journalLines()->exists()) {
                        $parent->forceFill(['is_header' => true])->save();
                    }

                    // Parents that already carry journal lines can never be promoted,
                    // therefore they cannot be given children.
                    if ($parent->journalLines()->exists()) {
                        $name = $parent->name ?: $parent->code;
                        throw ValidationException::withMessages([
                            'parent_id' => 'Akun induk "'.$name.'" sudah memiliki transaksi dan tidak bisa memiliki sub-akun.',
                        ]);
                    }
                }
            }
        });
    }

    public function isHeader(): bool
    {
        return (bool) $this->is_header;
    }

    public function isLeaf(): bool
    {
        return ! $this->isHeader();
    }

    public static function generateCode(string $type): string
    {
        // Archived accounts keep their code in the DB, so the next free code is
        // computed across deleted rows too to avoid a unique-index collision.
        $query = Account::query()->withTrashed()->where('type', $type);

        if (TenantContext::hasTenant()) {
            $query->where('tenant_id', TenantContext::id());
        }

        $next = $query->get()
            ->pluck('code')
            ->map(fn (string $code) => (int) substr($code, -4))
            ->max() ?? 0;

        return self::TYPE_CODE_PREFIXES[$type].'-'.str_pad((string) ($next + 1), 4, '0', STR_PAD_LEFT);
    }

    public function parent()
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function journalLines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function budgets()
    {
        return $this->belongsToMany(Budget::class, 'budget_accounts', 'account_id', 'budget_id')
            ->withTimestamps();
    }

    public function allocations()
    {
        return $this->belongsToMany(Allocation::class, 'account_allocations', 'account_id', 'allocation_id')
            ->withPivot('amount')
            ->withTimestamps();
    }

    /**
     * Total amount currently reserved by allocations on this account.
     */
    public function allocatedTotal(): float
    {
        return (float) $this->allocations()->sum('account_allocations.amount');
    }

    /**
     * Net ledger balance for this account from posted and archived journals
     * only (drafts are not real money yet), signed so it is positive when the
     * account is on its normal balance side.
     */
    public function postedNetBalance(): float
    {
        $totals = JournalLine::query()
            ->where('account_id', $this->id)
            ->whereHas('journal', fn ($query) => $query->whereIn('status', ['posted', 'archived']))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        $debit = (float) ($totals->debit ?? 0);
        $credit = (float) ($totals->credit ?? 0);
        $isDebitNormal = in_array($this->type, ['asset', 'expense'], true);

        return $isDebitNormal ? $debit - $credit : $credit - $debit;
    }
}
