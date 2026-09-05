<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'user_id', 'name', 'description', 'amount', 'budget_type', 'period_type', 'is_recurring', 'starts_at', 'ends_at'])]
class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'period_type' => 'string',
            'is_recurring' => 'boolean',
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function accounts()
    {
        return $this->belongsToMany(Account::class, 'budget_accounts', 'budget_id', 'account_id')
            ->withTimestamps();
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'budget_tags', 'budget_id', 'tag_id')
            ->withTimestamps();
    }
}
