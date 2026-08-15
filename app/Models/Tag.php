<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'description',
    ];

    public function journalTags()
    {
        return $this->hasMany(JournalTag::class);
    }

    public function journals()
    {
        return $this->belongsToMany(Journal::class, 'journal_tags')->withTimestamps();
    }

    public function budgets()
    {
        return $this->belongsToMany(Budget::class, 'budget_tags')->withTimestamps();
    }
}
