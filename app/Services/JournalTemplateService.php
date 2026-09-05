<?php

namespace App\Services;

use App\Models\Journal;
use App\Models\JournalTemplate;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class JournalTemplateService
{
    /**
     * Generate a journal entry from a template. Amounts default to the
     * template's line values but may be overridden per line.
     *
     * @param  array<int, array<string, mixed>>|null  $lineOverrides  keyed by index matching $template->lines order
     */
    public function generate(
        JournalTemplate $template,
        ?CarbonInterface $transactionDate = null,
        ?array $lineOverrides = null,
    ): Journal {
        return retry(
            times: 3,
            callback: fn () => DB::transaction(function () use ($template, $transactionDate, $lineOverrides) {
                // Ensure the tenant context is set so the Journal inherits tenant_id
                // and per-tenant reference generation works even when invoked from
                // the scheduler (no {tenant} route to set context).
                $previousTenant = TenantContext::current();
                TenantContext::set(Tenant::findOrFail($template->tenant_id));

                try {

                    $date = $transactionDate ?? now();

                    $journal = Journal::create([
                        'transaction_date' => $date,
                        'description' => $template->description
                            ? sprintf('%s — %s', $template->name, $template->description)
                            : $template->name,
                        'status' => 'draft',
                        'source' => 'system',
                    ]);

                    $defaultLines = $template->lines()->get();
                    $overrideRows = $lineOverrides ? array_values($lineOverrides) : [];

                    foreach ($defaultLines as $index => $line) {
                        $override = $overrideRows[$index] ?? [];

                        $journal->lines()->create([
                            'account_id' => $override['account_id'] ?? $line->account_id,
                            'line_number' => $index + 1,
                            'debit' => $override['debit'] ?? $line->debit,
                            'credit' => $override['credit'] ?? $line->credit,
                            'description' => $override['description'] ?? $line->description,
                        ]);
                    }

                    $journal->tags()->sync($template->tags()->pluck('tags.id'));

                    return $journal;
                } finally {
                    if ($previousTenant) {
                        TenantContext::set($previousTenant);
                    } else {
                        TenantContext::forget();
                    }
                }
            }),
            sleepMilliseconds: 50,
            when: fn (Throwable $e) => $e instanceof UniqueConstraintViolationException,
        );
    }

    /**
     * Advance a template's scheduling after a journal has been generated for it.
     */
    public function advanceSchedule(JournalTemplate $template, ?CarbonInterface $from = null): void
    {
        $template->update([
            'last_run_at' => now(),
            'next_run_at' => $template->nextRunDate($from ?? now()),
        ]);
    }

    /**
     * Generate journals for every active, due template. Returns the journals created.
     *
     * @return Collection<int, Journal>
     */
    public function processDue(?CarbonInterface $now = null): Collection
    {
        $now = $now ?? now();
        $created = collect();

        TenantContext::runInSystemContext(function () use ($now, $created): void {
            JournalTemplate::query()
                ->where('is_active', true)
                ->where(function ($query) use ($now) {
                    $query->whereNull('next_run_at')
                        ->orWhereDate('next_run_at', '<=', $now->toDateString());
                })
                ->get()
                ->each(function (JournalTemplate $template) use ($now, $created): void {
                    $journal = $this->generate($template, $now);
                    $created->push($journal);
                    $this->advanceSchedule($template, $now);
                });
        });

        return $created;
    }
}
