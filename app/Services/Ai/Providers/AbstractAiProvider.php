<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\Contracts\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class AbstractAiProvider implements AiProvider
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function isConfigured(): bool
    {
        return ! blank(config('ai.providers.'.static::name().'.api_key'));
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): ProviderResponse
    {
        try {
            $response = Http::baseUrl($this->config['base_uri'] ?? '')
                ->timeout($this->config['timeout'] ?? 30)
                ->withHeaders($this->headers())
                ->asJson()
                ->post($this->endpoint(), $this->requestPayload($messages, $options));
        } catch (ConnectionException $e) {
            Log::error('The AI provider could not be reached.', [
                'provider' => static::name(),
                'model' => $this->config['model'] ?? 'default',
                'error' => $e->getMessage(),
            ]);

            throw AiProviderException::unavailable(
                'The AI provider could not be reached.',
                AiProviderException::REASON_UNREACHABLE,
            );
        } catch (Throwable $e) {
            Log::error('The AI provider request failed.', [
                'provider' => static::name(),
                'model' => $this->config['model'] ?? 'default',
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            throw AiProviderException::unavailable(
                'The AI provider request failed.',
                AiProviderException::REASON_REQUEST_FAILED,
            );
        }

        return $this->parseResponse($response);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    abstract protected function requestPayload(array $messages, array $options = []): array;

    /**
     * @return array<string, string>
     */
    abstract protected function headers(): array;

    abstract protected function endpoint(): string;

    protected function parseResponse(Response $response): ProviderResponse
    {
        if ($response->failed()) {
            throw AiProviderException::unavailable(
                'The AI provider returned an error.',
                rawResponse: $response->json() ?? [],
            );
        }

        return new ProviderResponse(
            content: $this->extractContent($response),
            raw: $response->json() ?? [],
            usage: $this->extractUsage($response),
        );
    }

    abstract protected function extractContent(Response $response): string;

    /**
     * @return array<string, int|string>
     */
    abstract protected function extractUsage(Response $response): array;
}
