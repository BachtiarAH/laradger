<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Database\Factories\JournalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Journal extends Model
{
    /** @use HasFactory<JournalFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'reverse_from_id',
        'allocation_id',
        'goal_id',
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

        static::updating(function (Journal $journal) {
            $originalStatus = $journal->getOriginal('status');

            if ($originalStatus !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Posted or archived journals are immutable. Use reversal + correction journal instead.',
                ]);
            }
        });

        static::deleting(function (Journal $journal) {
            if ($journal->status !== 'draft') {
                throw ValidationException::withMessages([
                    'journal' => 'Only draft journals can be deleted.',
                ]);
            }

            if ($journal->auditLogs()->exists()) {
                throw ValidationException::withMessages([
                    'journal' => 'The journal cannot be deleted because it has audit logs.',
                ]);
            }

            if ($journal->reversals()->exists()) {
                throw ValidationException::withMessages([
                    'journal' => 'The journal cannot be deleted because it has reversals.',
                ]);
            }
        });
    }

    public static function nextReference(?CarbonInterface $transactionDate = null): string
    {
        $year = $transactionDate?->year ?? now()->year;

        // Archived journals keep their reference in the DB, so the next
        // sequence number is computed across deleted rows to avoid collisions.
        $query = static::query()->withTrashed()
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

    public function allocation()
    {
        return $this->belongsTo(Allocation::class);
    }

    public function goal()
    {
        return $this->belongsTo(Goal::class);
    }
}
