<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One turn in a conversation. Has no tenant_id of its own — it is always
 * reached through its conversation, which carries the tenant scope.
 */
class AiMessage extends Model
{
    use HasUuids;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    protected $fillable = [
        'ai_conversation_id',
        'user_id',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
        ];
    }

    /**
     * Tenant isolation comes from the parent conversation. Queries that skip it
     * would cross tenants, so the scope is fail-closed in the same way
     * BelongsToTenant is.
     */
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

    public function drafts(): HasMany
    {
        return $this->hasMany(AiActionDraft::class, 'ai_message_id');
    }
}
