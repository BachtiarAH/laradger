<?php

namespace App\Services\Ai\Gateway;

use App\Services\Ai\AiCallRecord;
use App\Services\Ai\Contracts\AiCallRecorder;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\Contracts\AiProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Providers\ProviderResponse;
use App\Services\Ai\Tasks\AiTask;
use App\Services\Ai\Tools\ToolCall;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use Throwable;

class AiGateway extends Manager
{
    /**
     * @var array<string, class-string<AiProvider>>
     */
    private const PROVIDERS = [
        'openai' => OpenAiProvider::class,
        'anthropic' => AnthropicProvider::class,
        'openai_compatible' => OpenAiCompatibleProvider::class,
    ];

    /**
     * The environment variable documented in the "not configured" message for
     * each provider. Kept next to the provider map so a renamed environment
     * variable can never drift from the hint shown to the user.
     *
     * @var array<string, string>
     */
    private const API_KEY_ENV = [
        'openai' => 'AI_OPENAI_API_KEY',
        'anthropic' => 'AI_ANTHROPIC_API_KEY',
        'openai_compatible' => 'AI_COMPATIBLE_API_KEY',
    ];

    public function __construct(
        private readonly AiCallRecorder $recorder,
        private readonly AiProviderConfigResolver $configs,
        Container $container,
    ) {
        parent::__construct($container);
    }

    public function getDefaultDriver(): string
    {
        return $this->configs->defaultFor(Auth::user());
    }

    /**
     * Every registered provider key, in registration order.
     *
     * @return array<int, string>
     */
    public static function providerNames(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * Build a provider adapter from an explicit configuration, bypassing the
     * driver cache and the stored user settings. Used to verify a key before
     * it is ever persisted.
     *
     * @param  array<string, mixed>  $config
     */
    public function providerFor(string $name, array $config): AiProvider
    {
        $provider = self::PROVIDERS[$name] ?? null;

        if ($provider === null) {
            throw AiProviderException::unavailable("The [{$name}] AI provider does not exist.");
        }

        return new $provider($config);
    }

    /**
     * Run an AI task against the default provider, falling back to other
     * configured providers when a call fails. Recording and logging of each
     * attempt happen here so tasks and providers stay free of cross-cutting
     * concerns.
     *
     * @param  array<string, mixed>  $context
     */
    public function run(AiTask $task, array $context = []): mixed
    {
        $default = $this->getDefaultDriver();
        $this->driver($default);

        if (! $this->driver($default)->isConfigured()) {
            throw AiProviderException::unavailable($this->unconfiguredMessage($default));
        }

        $prompt = $task->prompt($context);
        $statement = $task->statement($context);
        $messages = $task->messages($context);
        $options = $task->options();

        $lastException = null;

        foreach ($this->attempts($default) as $name) {
            $provider = $this->driver($name);
            $model = $this->configs->resolve($name, Auth::user())['model'] ?? 'default';

            $record = AiCallRecord::start(
                provider: $name,
                model: $model,
                user_id: Auth::id(),
                tenant_id: TenantContext::id(),
                statement: $statement,
                prompt: $prompt,
            );

            $start = hrtime(true);
            $response = null;

            try {
                $response = $provider->chat($messages, $options);
                $result = $task->interpret($response->content, $record->id);
                $payload = $this->normalizeResult($result);

                Log::info('AI provider returned a structured response.', [
                    'provider' => $name,
                    'model' => $model,
                    'prompt' => $prompt,
                    'result' => $payload,
                ]);

                $this->recorder->record(
                    $record->finish(
                        latencyMs: $this->elapsedMs($start),
                        success: true,
                        draft: $payload,
                        rawResponse: $response->raw,
                        usage: $response->usage,
                    ),
                );

                return $result;
            } catch (AiProviderException $e) {
                $this->logProviderFailure($e, $name, $model, $prompt);

                $this->recorder->record(
                    $record->finish(
                        latencyMs: $this->elapsedMs($start),
                        success: false,
                        error: $e->getMessage(),
                        rawResponse: $e->rawResponse ?? $response?->raw,
                    ),
                );

                $lastException = $e;
            } catch (Throwable $e) {
                Log::error('The AI provider request failed.', [
                    'provider' => $name,
                    'model' => $model,
                    'prompt' => $prompt,
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ]);

                $this->recorder->record(
                    $record->finish(
                        latencyMs: $this->elapsedMs($start),
                        success: false,
                        error: 'The AI provider request failed.',
                    ),
                );

                $lastException = AiProviderException::unavailable(
                    'The AI provider request failed.',
                    AiProviderException::REASON_REQUEST_FAILED,
                );
            }
        }

        throw $lastException ?? AiProviderException::unavailable('No AI provider was available.');
    }

    /**
     * One tool-aware round trip, with the same provider fallback as run().
     *
     * The agent loop lives in OmniAssistant, not here: the gateway owns
     * transport concerns (provider choice, fallback, recording, latency) and
     * knows nothing about what the tools do.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     */
    public function converse(array $messages, array $options = []): ProviderResponse
    {
        $default = $this->getDefaultDriver();
        $wantsTools = ($options['tools'] ?? []) !== [];

        if ($wantsTools && ! $this->driver($default)->supportsTools()) {
            throw AiProviderException::unavailable(
                "The {$default} AI provider does not support tool calling, so the assistant cannot use it. "
                .'Choose a different provider in your AI settings.'
            );
        }

        if (! $this->driver($default)->isConfigured()) {
            throw AiProviderException::unavailable($this->unconfiguredMessage($default));
        }

        $lastException = null;

        foreach ($this->attempts($default) as $name) {
            $candidate = $this->driver($name);

            // A fallback that cannot call tools would answer with prose instead
            // of proposing the action, so skip it rather than mislead the user.
            if ($wantsTools && ! $candidate->supportsTools()) {
                continue;
            }

            $model = $this->configs->resolve($name, Auth::user())['model'] ?? 'default';
            $summary = $this->summarize($messages);

            $record = AiCallRecord::start(
                provider: $name,
                model: $model,
                user_id: Auth::id(),
                tenant_id: TenantContext::id(),
                statement: null,
                prompt: $summary,
            );

            $start = hrtime(true);
            $response = null;

            try {
                $response = $candidate->chat($messages, $options);

                $this->recorder->record(
                    $record->finish(
                        latencyMs: $this->elapsedMs($start),
                        success: true,
                        draft: [
                            'content' => $response->content,
                            'tool_calls' => array_map(
                                static fn (ToolCall $call): array => $call->toArray(),
                                $response->toolCalls,
                            ),
                        ],
                        rawResponse: $response->raw,
                        usage: $response->usage,
                    ),
                );

                return $response;
            } catch (AiProviderException $e) {
                $this->logProviderFailure($e, $name, $model, $summary);

                $this->recorder->record(
                    $record->finish(
                        latencyMs: $this->elapsedMs($start),
                        success: false,
                        error: $e->getMessage(),
                        rawResponse: $e->rawResponse ?? $response?->raw,
                    ),
                );

                $lastException = $e;
            } catch (Throwable $e) {
                Log::error('The AI provider request failed.', [
                    'provider' => $name,
                    'model' => $model,
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ]);

                $this->recorder->record(
                    $record->finish(
                        latencyMs: $this->elapsedMs($start),
                        success: false,
                        error: 'The AI provider request failed.',
                    ),
                );

                $lastException = AiProviderException::unavailable(
                    'The AI provider request failed.',
                    AiProviderException::REASON_REQUEST_FAILED,
                );
            }
        }

        throw $lastException ?? AiProviderException::unavailable(
            'No AI provider able to run the assistant was available.'
        );
    }

    /**
     * A short, safe excerpt of the conversation for the call log. The full
     * transcript can hold tenant financials, so only the latest user turn is
     * kept.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function summarize(array $messages): string
    {
        $last = collect($messages)
            ->filter(static fn (array $message): bool => $message['role'] === 'user')
            ->last();

        return Str::limit((string) ($last['content'] ?? 'assistant turn'), 500);
    }

    /**
     * The default provider first, then every other provider that resolves to a
     * usable configuration — which may come from the environment or from the
     * key the requesting user stored in-app.
     *
     * @return array<int, string>
     */
    private function attempts(string $default): array
    {
        $attempts = [$default];

        foreach (array_keys(self::PROVIDERS) as $name) {
            if ($name === $default || ! $this->driver($name)->isConfigured()) {
                continue;
            }

            $attempts[] = $name;
        }

        return $attempts;
    }

    private function unconfiguredMessage(string $provider): string
    {
        $variable = self::API_KEY_ENV[$provider] ?? 'AI_DEFAULT_PROVIDER';

        return "The {$provider} AI provider is not configured. Add an API key in your AI settings, or set the {$variable} environment variable.";
    }

    protected function createOpenAiDriver(): AiProvider
    {
        return $this->buildProvider(OpenAiProvider::class, 'openai');
    }

    protected function createAnthropicDriver(): AiProvider
    {
        return $this->buildProvider(AnthropicProvider::class, 'anthropic');
    }

    protected function createOpenAiCompatibleDriver(): AiProvider
    {
        return $this->buildProvider(OpenAiCompatibleProvider::class, 'openai_compatible');
    }

    /**
     * @param  class-string<AiProvider>  $provider
     */
    protected function buildProvider(string $provider, string $name): AiProvider
    {
        return new $provider($this->configs->resolve($name, Auth::user()));
    }

    private function logProviderFailure(
        AiProviderException $exception,
        string $provider,
        string $model,
        string $prompt,
    ): void {
        $context = [
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
            'error' => $exception->getMessage(),
        ];

        if ($exception->rawResponse !== null) {
            $context['raw_response'] = $exception->rawResponse;
        }

        $message = match ($exception->reason) {
            AiProviderException::REASON_UNREACHABLE => 'The AI provider could not be reached.',
            AiProviderException::REASON_REQUEST_FAILED => 'The AI provider request failed.',
            default => 'AI provider returned an error.',
        };

        Log::error($message, $context);
    }

    private function normalizeResult(Arrayable|array $result): array
    {
        return $result instanceof Arrayable ? $result->toArray() : $result;
    }

    private function elapsedMs(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
