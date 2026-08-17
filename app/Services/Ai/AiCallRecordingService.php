<?php

namespace App\Services\Ai;

use App\Models\Journal;
use App\Services\Ai\Contracts\AiCallRecorder;
use App\Services\Ai\Recorders\FileAiCallRecorder;
use Illuminate\Support\Manager;

class AiCallRecordingService extends Manager implements AiCallRecorder
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('ai.recording.driver', 'file');
    }

    public function record(AiCallRecord $record): void
    {
        if (! $this->config->get('ai.recording.enabled', true)) {
            return;
        }

        $this->driver()->record($record);
    }

    public function confirm(string $recordId, Journal $journal): void
    {
        if (! $this->config->get('ai.recording.enabled', true)) {
            return;
        }

        $this->driver()->confirm($recordId, $journal);
    }

    protected function createFileDriver(): AiCallRecorder
    {
        return new FileAiCallRecorder(
            $this->config->get('ai.recording.file.path', 'logs/ai-calls.jsonl'),
        );
    }
}
