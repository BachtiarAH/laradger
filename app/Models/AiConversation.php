<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AiConversationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiConversation extends Model
{
    /** @use HasFactory<AiConversationFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    /** No turn requested; the thread is ready for input. */
    public const STATUS_IDLE = 'idle';

    /** Accepted and waiting for a worker. */
    public const STATUS_QUEUED = 'queued';

    /** A worker is running the turn right now. */
    public const STATUS_RUNNING = 'running';

    /** Finished; any drafts it produced are waiting for review. */
    public const STATUS_COMPLETED = 'completed';

    /** The turn could not be completed. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'title',
        'status',
        'error',
        'queued_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * True while a turn is queued or in flight, so a second one can be refused
     * rather than interleaving two transcripts.
     */
    public function isBusy(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'ai_conversation_id')->orderBy('created_at');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(AiActionDraft::class, 'ai_conversation_id');
    }

    public function pendingDrafts(): HasMany
    {
        return $this->drafts()->where('status', AiActionDraft::STATUS_PENDING);
    }
}
