<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AiDraftRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A prompt submitted to be drafted in the background.
 *
 * Distinct from a chat conversation on purpose: chatting is synchronous and
 * conversational, drafting is fire-and-forget. Each request carries its own
 * status so the user can see, at a glance, which of the prompts they sent are
 * still queued, still running, done, or failed.
 */
class AiDraftRequest extends Model
{
    /** @use HasFactory<AiDraftRequestFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'ai_conversation_id',
        'prompt',
        'status',
        'error',
        'drafts_count',
        'queued_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'drafts_count' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(AiActionDraft::class, 'ai_draft_request_id');
    }

    public function isSettled(): bool
    {
        return ! in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }
}
