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

    protected $fillable = [
        'tenant_id',
        'user_id',
        'title',
    ];

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
