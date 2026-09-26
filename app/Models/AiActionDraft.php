<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed action awaiting the user's decision.
 *
 * This row is the whole safety mechanism: the model proposing something and the
 * user approving it are separate events, separated by a reviewable payload. No
 * tool runs on the model's say-so.
 *
 * Has no tenant_id of its own — always reached through its conversation, which
 * carries the tenant scope.
 */
class AiActionDraft extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'ai_conversation_id',
        'ai_message_id',
        'user_id',
        'tool',
        'kind',
        'title',
        'payload',
        'status',
        'result',
        'error',
        'executed_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'executed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $builder->whereHas('conversation');
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'ai_message_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSettled(): bool
    {
        return ! $this->isPending();
    }
}
