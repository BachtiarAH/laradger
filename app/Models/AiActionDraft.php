<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    /**
     * Drafts that must be applied before this one.
     *
     * Real rows rather than the `pending:<name>` strings in the payload, because a
     * string cannot be ordered, cannot be shown on the review card, and cannot be
     * checked before anything runs. The payload keeps the name — it is what the
     * review card displays, and what review-still-equals-sent depends on — and
     * this relation is what the executor walks.
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'ai_action_draft_dependencies',
            'draft_id',
            'depends_on_id',
        )->withTimestamps();
    }

    /** The drafts that break if this one is discarded. */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'ai_action_draft_dependencies',
            'depends_on_id',
            'draft_id',
        )->withTimestamps();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSettled(): bool
    {
        return ! $this->isPending();
    }

    /**
     * Whether the payload may still be corrected by hand.
     *
     * A failed draft is editable on purpose. It is usually failed because the
     * payload itself is wrong — unbalanced lines, a reference that cannot be
     * resolved — and a draft you cannot correct is a draft you can only throw away
     * and ask the assistant to produce again.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED], true);
    }

    /**
     * Whether this draft may be run again.
     *
     * `failed` is allowed because the executor wraps each run in a transaction, so
     * a failure rolled back and left nothing behind. `executed` and `rejected` are
     * not: re-running those is the double-posting this whole class exists to
     * prevent.
     */
    public function isExecutable(): bool
    {
        return $this->isEditable();
    }
}
