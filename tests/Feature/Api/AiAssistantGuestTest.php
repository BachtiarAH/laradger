<?php

use App\Models\AiActionDraft;
use App\Models\AiConversation;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    $this->base = "/api/v1/{$this->tenant->slug}";
});

test('guests cannot list or start a conversation', function () {
    $this->getJson("{$this->base}/ai/conversations")->assertUnauthorized();
    $this->postJson("{$this->base}/ai/conversations")->assertUnauthorized();
});

test('guests cannot read a conversation, send a message, or touch drafts', function () {
    $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();
    $draft = $conversation->drafts()->create([
        'user_id' => $this->user->id,
        'tool' => 'journal_create',
        'kind' => 'write',
        'title' => 'Private',
        'status' => AiActionDraft::STATUS_PENDING,
        'payload' => [],
    ]);

    $this->getJson("{$this->base}/ai/conversations/{$conversation->id}")->assertUnauthorized();
    $this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [
        'message' => 'hello',
    ])->assertUnauthorized();
    $this->getJson("{$this->base}/ai/drafts")->assertUnauthorized();
    $this->patchJson("{$this->base}/ai/drafts/{$draft->id}", ['payload' => ['a' => 1]])->assertUnauthorized();
    $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")->assertUnauthorized();
    $this->postJson("{$this->base}/ai/drafts/{$draft->id}/reject")->assertUnauthorized();
});
