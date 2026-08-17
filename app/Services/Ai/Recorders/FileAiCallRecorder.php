<?php

namespace App\Services\Ai\Recorders;

use App\Models\Journal;
use App\Services\Ai\AiCallRecord;
use App\Services\Ai\Contracts\AiCallRecorder;

class FileAiCallRecorder implements AiCallRecorder
{
    public function __construct(
        private readonly string $path,
    ) {}

    public function record(AiCallRecord $record): void
    {
        $this->append($record);
    }

    public function confirm(string $recordId, Journal $journal): void
    {
        $record = AiCallRecord::confirmation(
            recordId: $recordId,
            journalId: $journal->getKey(),
            userId: $journal->user_id,
            tenantId: $journal->tenant_id,
        );

        $this->append($record);
    }

    private function append(AiCallRecord $record): void
    {
        $path = str_starts_with($this->path, DIRECTORY_SEPARATOR)
            ? $this->path
            : storage_path($this->path);

        file_put_contents(
            $path,
            json_encode($record->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
