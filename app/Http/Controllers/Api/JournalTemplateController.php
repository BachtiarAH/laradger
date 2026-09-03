<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateJournalRequest;
use App\Http\Requests\StoreJournalTemplateRequest;
use App\Http\Requests\UpdateJournalTemplateRequest;
use App\Http\Resources\JournalResource;
use App\Http\Resources\JournalTemplateResource;
use App\Models\JournalTemplate;
use App\Services\JournalTemplateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class JournalTemplateController extends Controller
{
    public function __construct(
        private readonly JournalTemplateService $templateService,
    ) {}

    public function index(string $tenant): AnonymousResourceCollection
    {
        $this->authorize('viewAny', JournalTemplate::class);

        $templates = JournalTemplate::withCount('lines')
            ->with('tags')
            ->when(request('period_type'), fn ($q) => $q->where('period_type', request('period_type')))
            ->when(request('is_active'), fn ($q) => $q->where('is_active', filter_var(request('is_active'), FILTER_VALIDATE_BOOLEAN)))
            ->when(request('search'), fn ($q) => $q->where('name', 'like', '%'.request('search').'%'))
            ->latest()
            ->paginate();

        return JournalTemplateResource::collection($templates);
    }

    public function store(string $tenant, StoreJournalTemplateRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['lines', 'tags']);
        $lines = $request->validated('lines', []);
        $tags = $request->validated('tags', []);

        $template = DB::transaction(function () use ($data, $lines, $tags) {
            $template = JournalTemplate::create($data);

            foreach ($lines as $index => $line) {
                $template->lines()->create($line + ['line_number' => $index + 1]);
            }

            $template->tags()->sync($tags);

            $template->update(['next_run_at' => $template->nextRunDate()]);

            return $template;
        });

        return (new JournalTemplateResource($template->load('lines.account', 'tags')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $tenant, JournalTemplate $journalTemplate): JournalTemplateResource
    {
        $this->authorize('view', $journalTemplate);

        return new JournalTemplateResource($journalTemplate->load('lines.account', 'tags'));
    }

    public function update(string $tenant, UpdateJournalTemplateRequest $request, JournalTemplate $journalTemplate): JournalTemplateResource
    {
        $this->authorize('update', $journalTemplate);

        $data = $request->safe()->except(['lines', 'tags']);

        DB::transaction(function () use ($request, $journalTemplate, $data) {
            $journalTemplate->update($data);

            if ($request->has('lines')) {
                $journalTemplate->lines()->delete();
                foreach ($request->validated('lines', []) as $index => $line) {
                    $journalTemplate->lines()->create($line + ['line_number' => $index + 1]);
                }
            }

            if ($request->has('tags')) {
                $journalTemplate->tags()->sync($request->validated('tags', []));
            }
        });

        return new JournalTemplateResource($journalTemplate->fresh('lines.account', 'tags'));
    }

    public function destroy(string $tenant, JournalTemplate $journalTemplate): JsonResponse
    {
        $this->authorize('delete', $journalTemplate);

        $journalTemplate->delete();

        return response()->json(null, 204);
    }

    public function generate(string $tenant, GenerateJournalRequest $request, JournalTemplate $journalTemplate): JsonResponse
    {
        $this->authorize('generate', $journalTemplate);

        $transactionDate = $request->filled('transaction_date')
            ? Carbon::parse($request->input('transaction_date'))
            : null;

        $journal = $this->templateService->generate(
            template: $journalTemplate,
            transactionDate: $transactionDate,
            lineOverrides: $request->has('lines') ? $request->validated('lines') : null,
        );

        $this->templateService->advanceSchedule($journalTemplate, $transactionDate ?? now());

        return (new JournalResource($journal->load('lines.account', 'tags')))
            ->response()
            ->setStatusCode(201);
    }
}
