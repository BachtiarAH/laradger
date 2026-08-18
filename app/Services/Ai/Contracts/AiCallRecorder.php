<?php

namespace App\Services\Ai\Contracts;

use App\Models\Journal;
use App\Services\Ai\AiCallRecord;

interface AiCallRecorder
{
    public function record(AiCallRecord $record): void;

    public function confirm(string $recordId, Journal $journal): void;
}
