<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'action',
        'before',
        'after',
        'reason',
        'journal_id',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }
}
