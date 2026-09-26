<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\Contracts\AiProvider;
use App\Services\Ai\Tools\ToolCall;
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

    public function isConfigured(): bool
    {
        return filled($this->config['api_key'] ?? null);
    }

    /**
     * Tool names are sent verbatim to the provider, and both OpenAI and
     * Anthropic restrict function names to `^[a-zA-Z0-9_-]+$`. A dotted name is
     * rejected outright by the API, so names are underscored throughout.
     */
    public function supportsTools(): bool
    {
        return false;
    }

    /**
     * The tool definitions to expose on this request, in the normalized shape.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, array{name: string, description: string, parameters: array<string, mixed>}>
     */
    protected function requestedTools(array $options): array
    {
        return (array) ($options['tools'] ?? []);
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

        $toolCalls = $this->extractToolCalls($response);
        $content = $this->extractContent($response);

        // A turn that only requests tools carries no text, which is valid. A
        // turn with neither text nor tool calls is a broken response.
        if (blank($content) && $toolCalls === []) {
            throw AiProviderException::invalidResponse(
                'The AI provider returned an empty response.',
                rawResponse: $response->json() ?? [],
            );
        }

        return new ProviderResponse(
            content: $content,
            raw: $response->json() ?? [],
            usage: $this->extractUsage($response),
            toolCalls: $toolCalls,
        );
    }

    /**
     * The assistant's textual reply, or an empty string when the provider
     * replied with tool calls only. Returning '' rather than throwing lets
     * parseResponse() apply the empty-response rule once, in one place.
     */
    abstract protected function extractContent(Response $response): string;

    /**
     * Tool calls requested by the provider, normalized to ToolCall. Providers
     * that cannot call tools return an empty array.
     *
     * @return array<int, ToolCall>
     */
    protected function extractToolCalls(Response $response): array
    {
        return [];
    }

    /**
     * @return array<string, int|string>
     */
    abstract protected function extractUsage(Response $response): array;
}
