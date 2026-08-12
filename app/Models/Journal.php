<?php

namespace App\Models;

use Database\Factories\JournalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    /** @use HasFactory<JournalFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'reverse_from_id',
        'transaction_date',
        'description',
        'reference',
        'status',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    public function reversedFrom()
    {
        return $this->belongsTo(Journal::class, 'reverse_from_id');
    }

    public function reversals()
    {
        return $this->hasMany(Journal::class, 'reverse_from_id');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'journal_tags')->withTimestamps();
    }
}
