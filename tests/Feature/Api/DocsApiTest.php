<?php

test('the openapi specification is rendered inline as text by default', function () {
    $this->get('/api/docs')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertHeaderMissing('Content-Disposition')
        ->assertSee('openapi: 3.1.0', false)
        ->assertSee('Ledgify API');
});

test('the openapi specification is downloaded when the download param is present', function () {
    $this->get('/api/docs?download=1')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/yaml; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="openapi.yaml"')
        ->assertSee('openapi: 3.1.0', false);
});

test('the openapi specification is served as json when requested', function () {
    $this->getJson('/api/docs')
        ->assertOk()
        ->assertJsonPath('info.title', 'Ledgify API')
        ->assertJsonPath('openapi', '3.1.0');
});

test('the openapi specification documents the allocation endpoints', function () {
    $this->getJson('/api/docs')
        ->assertOk()
        ->assertJsonPath('paths./{tenant}/allocations.get.summary', 'List allocations')
        ->assertJsonPath('paths./{tenant}/allocations/{allocation}/allocate.post.summary', 'Allocate money on an account')
        ->assertJsonPath('paths./{tenant}/accounts/{account}/allocations.get.summary', 'Show the allocation summary for an account');
});

test('the openapi specification documents the expense list filters', function () {
    $params = $this->getJson('/api/docs')
        ->assertOk()
        ->assertJsonPath('paths./{tenant}/expenses.get.summary', 'List expenses')
        ->json('paths./{tenant}/expenses.get.parameters');

    expect(array_column($params, 'name'))->toContain('from', 'to', 'account_id', 'search');
});

test('the openapi specification documents the ai settings endpoints', function () {
    $this->getJson('/api/docs')
        ->assertOk()
        ->assertJsonPath('paths./me/ai.get.summary', "Show the authenticated user's AI provider settings")
        ->assertJsonPath('paths./me/ai.put.summary', "Store the authenticated user's AI provider settings")
        ->assertJsonPath('paths./me/ai.delete.summary', 'Remove the stored AI API key and provider selection')
        ->assertJsonPath('paths./me/ai/test.post.summary', 'Verify AI credentials against the provider');
});

test('the openapi specification documents the assistant action endpoints', function () {
    $this->getJson('/api/docs')
        ->assertOk()
        ->assertJsonPath('paths./{tenant}/ai/conversations.post.summary', 'Start a conversation')
        ->assertJsonPath(
            'paths./{tenant}/ai/conversations/{conversation}/messages.post.summary',
            'Send a message and queue an assistant turn',
        )
        ->assertJsonPath(
            'paths./{tenant}/ai/conversations/{conversation}/status.get.summary',
            'Poll how far an assistant turn has got',
        )
        ->assertJsonPath('paths./{tenant}/ai/drafts.get.summary', "List the caller's proposed actions")
        ->assertJsonPath('paths./{tenant}/ai/drafts/{draft}.patch.summary', "Correct a draft's payload before approving it")
        ->assertJsonPath('paths./{tenant}/ai/drafts/{draft}/execute.post.summary', 'Approve a draft and apply it')
        ->assertJsonPath('paths./{tenant}/ai/drafts/{draft}/reject.post.summary', 'Discard a draft');
});

test('the openapi specification documents the queued turn as accepted, not done', function () {
    $this->getJson('/api/docs')
        ->assertOk()
        // 202, because the turn happens in the background.
        ->assertJsonStructure([
            'paths' => [
                '/{tenant}/ai/conversations/{conversation}/messages' => [
                    'post' => ['responses' => ['202', '409']],
                ],
            ],
        ]);
});
