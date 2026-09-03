<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AllocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Allocation extends Model
{
    /** @use HasFactory<AllocationFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'target_amount',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
        ];
    }

    /**
     * Accounts this allocation reserves money on, with the reserved amount.
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_allocations', 'allocation_id', 'account_id')
            ->withPivot('amount')
            ->withTimestamps();
    }

    /**
     * Sum of every reservation across all accounts for this allocation.
     */
    public function allocatedTotal(): float
    {
        return (float) $this->accounts()->sum('account_allocations.amount');
    }
}
