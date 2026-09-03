<?php

namespace App\Console\Commands;

use App\Services\JournalTemplateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('journal-templates:process')]
#[Description('Generate journal entries from due journal templates (daily/weekly/monthly)')]
class ProcessJournalTemplates extends Command
{
    public function handle(JournalTemplateService $service): int
    {
        $created = $service->processDue();

        $this->info(sprintf('Processed journal templates: %d journal(s) created.', $created->count()));

        return self::SUCCESS;
    }
}
