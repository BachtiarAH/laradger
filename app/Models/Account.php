<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

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
        'parent_id',
        'currency',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (Account $account): void {
            if (! $account->code) {
                $account->code = self::generateCode($account->type);
            }
        });
    }

    public static function generateCode(string $type): string
    {
        $next = Account::query()
            ->where('type', $type)
            ->get()
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
