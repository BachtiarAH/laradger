<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiCallRecorder;
use App\Services\Ai\Contracts\JournalDraftProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\AnthropicJournalDraftProvider;
use App\Services\Ai\Providers\OpenAiCompatibleJournalDraftProvider;
use App\Services\Ai\Providers\OpenAiJournalDraftProvider;
use Illuminate\Support\Manager;

class JournalDraftService extends Manager
{
    /**
     * @var array<string, class-string<JournalDraftProvider>>
     */
    private const PROVIDERS = [
        'openai' => OpenAiJournalDraftProvider::class,
        'anthropic' => AnthropicJournalDraftProvider::class,
        'openai_compatible' => OpenAiCompatibleJournalDraftProvider::class,
    ];

    public function getDefaultDriver(): string
    {
        return $this->config->get('ai.default', 'openai');
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     */
    public function draft(string $statement, array $accounts): JournalDraft
    {
        return $this->driver()->draft($statement, $accounts);
    }

    /**
     * Try the default provider first, then fall back to another configured provider.
     *
     * @param  array<int, array<string, mixed>>  $accounts
     */
    public function draftWithFallback(string $statement, array $accounts): JournalDraft
    {
        $default = $this->getDefaultDriver();

        try {
            return $this->driver($default)->draft($statement, $accounts);
        } catch (AiProviderException $e) {
            $fallback = $this->fallbackDriverName($default);

            if ($fallback !== null) {
                return $this->driver($fallback)->draft($statement, $accounts);
            }

            throw $e;
        }
    }

    protected function fallbackDriverName(?string $exclude = null): ?string
    {
        foreach (array_keys(self::PROVIDERS) as $name) {
            if ($name === $exclude) {
                continue;
            }

            $provider = self::PROVIDERS[$name];

            if ($provider::isConfigured()) {
                return $name;
            }
        }

        return null;
    }

    protected function createOpenAiDriver(): JournalDraftProvider
    {
        return $this->buildProvider(OpenAiJournalDraftProvider::class, 'openai');
    }

    protected function createAnthropicDriver(): JournalDraftProvider
    {
        return $this->buildProvider(AnthropicJournalDraftProvider::class, 'anthropic');
    }

    protected function createOpenAiCompatibleDriver(): JournalDraftProvider
    {
        return $this->buildProvider(OpenAiCompatibleJournalDraftProvider::class, 'openai_compatible');
    }

    /**
     * @param  class-string<JournalDraftProvider>  $provider
     */
    protected function buildProvider(string $provider, string $name): JournalDraftProvider
    {
        if (! $provider::isConfigured()) {
            throw AiProviderException::unavailable(
                "The {$name} AI provider is not configured. Set the AI_{$name}_API_KEY environment variable."
            );
        }

        return new $provider(
            $this->config->get("ai.providers.{$name}"),
            app(AiCallRecorder::class),
        );
    }
}
