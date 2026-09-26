<?php

use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * The drafting page follows a submitted prompt by polling. That polling used to
 * share the `ai` rate limit — 30/min, which exists because every assistant turn
 * spends the user's own money — and the page spent three requests every 2.5
 * seconds doing it: 72/min against a 30/min ceiling, so a queued prompt
 * throttled the very screen watching it, and then the next real message.
 *
 * These pin the split: the two free reads get their own budget, and everything
 * that can actually spend money keeps the tight one.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    [$this->tenant, $this->base] = draftRequestTenant($this->user);
});

test('the status reads the drafting page polls are not rate limited like a paid call', function () {
    // Comfortably past the 30/min the `ai` limiter allows, and past what the page
    // actually issues now (~24/min at its fastest, then backing off).
    for ($i = 0; $i < 40; $i++) {
        $this->getJson("{$this->base}/ai/drafts")->assertOk();
        $this->getJson("{$this->base}/ai/draft-requests")->assertOk();
    }
});

test('sending an assistant turn is still rate limited', function () {
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ])]);

    $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

    $rejected = 0;

    for ($i = 0; $i < 35; $i++) {
        // 30 is the ceiling; anything past that must be turned away.
        if ($this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [
            'message' => 'hello',
        ])->status() === 429) {
            $rejected++;
        }
    }

    expect($rejected)->toBeGreaterThan(0);
});
