<?php

use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Tools\ToolCall;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'ai.providers.openai.api_key' => 'k',
        'ai.providers.anthropic.api_key' => 'k',
        'ai.providers.openai_compatible.api_key' => 'k',
    ]);
});

function openAi(): OpenAiProvider
{
    return new OpenAiProvider(config('ai.providers.openai'));
}

function anthropic(): AnthropicProvider
{
    return new AnthropicProvider(config('ai.providers.anthropic'));
}

function toolDefinitions(): array
{
    return [[
        'name' => 'journal_create',
        'description' => 'Create a journal.',
        'parameters' => ['type' => 'object', 'properties' => ['description' => ['type' => 'string']]],
    ]];
}

describe('openai tool translation', function () {
    test('sends tool definitions in the function wrapper shape', function () {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        openAi()->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['tools' => toolDefinitions()],
        );

        Http::assertSent(function ($request) {
            $tools = $request->data()['tools'] ?? [];

            return ($tools[0]['type'] ?? null) === 'function'
                && ($tools[0]['function']['name'] ?? null) === 'journal_create'
                && ($tools[0]['function']['parameters']['type'] ?? null) === 'object';
        });
    });

    test('parses tool calls and decodes string arguments', function () {
        Http::fake(['*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_abc',
                        'type' => 'function',
                        'function' => [
                            'name' => 'journal_create',
                            'arguments' => '{"transaction_date":"2026-08-17","lines":[]}',
                        ],
                    ]],
                ],
            ]],
        ])]);

        $response = openAi()->chat([['role' => 'user', 'content' => 'hi']], ['tools' => toolDefinitions()]);

        expect($response->hasToolCalls())->toBeTrue()
            ->and($response->content)->toBe('')
            ->and($response->toolCalls[0]->id)->toBe('call_abc')
            ->and($response->toolCalls[0]->name)->toBe('journal_create')
            // OpenAI sends arguments as a JSON string; it must arrive as an array.
            ->and($response->toolCalls[0]->arguments)->toBe([
                'transaction_date' => '2026-08-17',
                'lines' => [],
            ]);
    });

    test('replays assistant tool calls and tool results in the wire format', function () {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'done']]]])]);

        openAi()->chat([
            ['role' => 'user', 'content' => 'record 10 coffee'],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [new ToolCall('call_1', 'journal_create', ['description' => 'Coffee'])],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"status":"proposed"}'],
        ], ['tools' => toolDefinitions()]);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'];

            return $messages[1]['tool_calls'][0]['function']['name'] === 'journal_create'
                // Arguments go out as a JSON string, not an object.
                && $messages[1]['tool_calls'][0]['function']['arguments'] === '{"description":"Coffee"}'
                // An empty assistant content must be null, not "", for tool turns.
                && $messages[1]['content'] === null
                && $messages[2]['role'] === 'tool'
                && $messages[2]['tool_call_id'] === 'call_1';
        });
    });

    test('malformed arguments degrade to an empty array rather than throwing', function () {
        Http::fake(['*' => Http::response([
            'choices' => [[
                'message' => [
                    'tool_calls' => [[
                        'id' => 'c1',
                        'function' => ['name' => 'journal_create', 'arguments' => 'not json'],
                    ]],
                ],
            ]],
        ])]);

        expect(openAi()->chat([['role' => 'user', 'content' => 'x']], ['tools' => toolDefinitions()])
            ->toolCalls[0]->arguments)->toBe([]);
    });

    test('an empty reply with no tool calls is an invalid response', function () {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '']]]])]);

        expect(fn () => openAi()->chat([['role' => 'user', 'content' => 'x']]))
            ->toThrow(AiProviderException::class, 'empty response');
    });
});

describe('anthropic tool translation', function () {
    test('sends tools with input_schema, not a function wrapper', function () {
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]])]);

        anthropic()->chat([['role' => 'user', 'content' => 'hi']], ['tools' => toolDefinitions()]);

        Http::assertSent(function ($request) {
            $tools = $request->data()['tools'] ?? [];

            return ($tools[0]['name'] ?? null) === 'journal_create'
                && ($tools[0]['input_schema']['type'] ?? null) === 'object'
                // Anthropic has no function/type wrapper at all.
                && ! array_key_exists('type', $tools[0] ?? []);
        });
    });

    test('parses tool_use blocks and keeps input as an array', function () {
        Http::fake(['*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => 'Let me check.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'journal_create', 'input' => ['description' => 'Coffee']],
            ],
        ])]);

        $response = anthropic()->chat([['role' => 'user', 'content' => 'x']], ['tools' => toolDefinitions()]);

        expect($response->hasToolCalls())->toBeTrue()
            ->and($response->content)->toBe('Let me check.')
            ->and($response->toolCalls[0]->id)->toBe('toolu_1')
            ->and($response->toolCalls[0]->arguments)->toBe(['description' => 'Coffee']);
    });

    test('replays a tool result as a user turn holding a tool_result block', function () {
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'done']]])]);

        anthropic()->chat([
            ['role' => 'user', 'content' => 'record 10 coffee'],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [new ToolCall('toolu_1', 'journal_create', ['description' => 'Coffee'])],
            ],
            ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => '{"status":"proposed"}'],
        ], ['tools' => toolDefinitions()]);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'];

            return $messages[1]['content'][0]['type'] === 'tool_use'
                && $messages[1]['content'][0]['name'] === 'journal_create'
                // Anthropic has no `tool` role: the result rides a user turn.
                && $messages[2]['role'] === 'user'
                && $messages[2]['content'][0]['type'] === 'tool_result'
                && $messages[2]['content'][0]['tool_use_id'] === 'toolu_1';
        });
    });

    test('hoists the system prompt out of the message list', function () {
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]])]);

        anthropic()->chat([
            ['role' => 'system', 'content' => 'You are a bookkeeper.'],
            ['role' => 'user', 'content' => 'hi'],
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['system'] ?? null) === 'You are a bookkeeper.'
                && count($data['messages']) === 1
                && $data['messages'][0]['role'] === 'user';
        });
    });

    test('merges consecutive user turns, which the api rejects', function () {
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]])]);

        anthropic()->chat([
            ['role' => 'user', 'content' => 'first'],
            ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => '{"ok":true}'],
        ], ['tools' => toolDefinitions()]);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'];

            // Both normalize to a user turn, so they must be folded into one.
            return count($messages) === 1
                && $messages[0]['role'] === 'user'
                && count($messages[0]['content']) === 2
                && $messages[0]['content'][1]['type'] === 'tool_result';
        });
    });

    test('tolerates content blocks that omit the type field', function () {
        // Real Anthropic always sends `type`, but a proxy fronting an
        // Anthropic-compatible model may not, and dropping the reply over it
        // would be silent data loss.
        Http::fake(['*' => Http::response([
            'content' => [
                ['text' => 'Here you go.'],
                ['id' => 'toolu_9', 'name' => 'journal_create', 'input' => ['description' => 'Coffee']],
            ],
        ])]);

        $response = anthropic()->chat([['role' => 'user', 'content' => 'x']], ['tools' => toolDefinitions()]);

        expect($response->content)->toBe('Here you go.')
            ->and($response->toolCalls)->toHaveCount(1)
            ->and($response->toolCalls[0]->name)->toBe('journal_create');
    });

    test('ignores system and empty turns', function () {
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]])]);

        anthropic()->chat([
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'assistant', 'content' => ''],
            ['role' => 'user', 'content' => 'hi'],
        ]);

        Http::assertSent(fn ($request) => count($request->data()['messages']) === 1);
    });
});

describe('tool support flags', function () {
    test('the hosted providers support tools', function () {
        expect(openAi()->supportsTools())->toBeTrue()
            ->and(anthropic()->supportsTools())->toBeTrue();
    });

    test('the compatible provider supports tools by default and can opt out', function () {
        $config = config('ai.providers.openai_compatible');

        expect((new OpenAiCompatibleProvider($config))->supportsTools())->toBeTrue()
            ->and((new OpenAiCompatibleProvider([...$config, 'supports_tools' => false]))->supportsTools())->toBeFalse();
    });
});
