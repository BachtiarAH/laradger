<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'name', 'amount', 'starts_at', 'ends_at'])]
class Budget extends Model
{
    use HasUuids;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function accounts()
    {
        return $this->belongsToMany(Account::class, 'budget_accounts', 'budget_id', 'account_id')
            ->withTimestamps();
    }
}
