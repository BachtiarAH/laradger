<?php

namespace App\Services\Ai\Gateway;

use App\Services\Ai\AiCallRecord;
use App\Services\Ai\Contracts\AiCallRecorder;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\Contracts\AiProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Tasks\AiTask;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Manager;
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

    public function __construct(
        private readonly AiCallRecorder $recorder,
        Container $container,
    ) {
        parent::__construct($container);
    }

    public function getDefaultDriver(): string
    {
        return $this->config->get('ai.default', 'openai');
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

        $prompt = $task->prompt($context);
        $statement = $task->statement($context);
        $messages = $task->messages($context);
        $options = $task->options();

        $lastException = null;

        foreach ($this->attempts($default) as $name) {
            $provider = $this->driver($name);
            $model = $this->config->get("ai.providers.{$name}.model") ?? 'default';

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
     * @return array<int, string>
     */
    private function attempts(string $default): array
    {
        $attempts = [$default];

        foreach (array_keys(self::PROVIDERS) as $name) {
            if ($name === $default || ! self::PROVIDERS[$name]::isConfigured()) {
                continue;
            }

            $attempts[] = $name;
        }

        return $attempts;
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
        if (! $provider::isConfigured()) {
            throw AiProviderException::unavailable(
                "The {$name} AI provider is not configured. Set the AI_{$name}_API_KEY environment variable."
            );
        }

        return new $provider(
            $this->config->get("ai.providers.{$name}"),
        );
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
