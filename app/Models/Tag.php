<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'type',
    ];

    public function journalTags()
    {
        return $this->hasMany(JournalTag::class);
    }

    public function journals()
    {
        return $this->belongsToMany(Journal::class, 'journal_tags')->withTimestamps();
    }
}
