<?php

namespace App\Models;

use Database\Factories\JournalTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalTag extends Model
{
    /** @use HasFactory<JournalTagFactory> */
    use HasFactory;

    protected $fillable = [
        'journal_id',
        'tag_id',
    ];

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    public function tag()
    {
        return $this->belongsTo(Tag::class);
    }
}
