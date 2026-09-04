<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Database\Factories\JournalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    /** @use HasFactory<JournalFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
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

    protected static function booted(): void
    {
        static::creating(function (Journal $journal) {
            if (blank($journal->reference)) {
                $journal->reference = static::nextReference($journal->transaction_date);
            }
        });
    }

    public static function nextReference(?CarbonInterface $transactionDate = null): string
    {
        $year = $transactionDate?->year ?? now()->year;

        $query = static::query()
            ->whereYear('transaction_date', $year)
            ->where('reference', 'like', "JRN-{$year}-%");

        if (TenantContext::hasTenant()) {
            $query->where('tenant_id', TenantContext::id());
        }

        $max = $query->max('reference');

        $sequence = $max ? ((int) substr($max, (int) strrpos($max, '-') + 1)) + 1 : 1;

        return sprintf('JRN-%s-%04d', $year, $sequence);
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_number');
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
