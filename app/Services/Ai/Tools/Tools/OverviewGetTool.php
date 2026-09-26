<?php

namespace App\Services\Ai\Tools\Tools;

use App\Http\Controllers\Api\OverviewController;
use App\Services\Ai\Tools\AbstractAiTool;
use App\Services\Ai\Tools\AiToolKind;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

class OverviewGetTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'overview_get';
    }

    public function description(): string
    {
        return 'Get the dashboard summary: income, expenses, budget figures, and safe money '
            .'(how much is genuinely available to spend after allocations). Use this to answer '
            .'"how much can I spend" or "how are we doing this month" questions.';
    }

    public function kind(): AiToolKind
    {
        return AiToolKind::Read;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'enum' => ['today', 'this_week', 'this_month'],
                    'description' => 'Optional reporting period. Defaults to this month.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function title(array $arguments): string
    {
        return 'Read the overview';
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $request = Request::create('/overview', 'GET', array_filter([
            'period' => $this->stringArgument($arguments, 'period') ?: null,
        ]));

        $request->setUserResolver(fn (): mixed => auth()->user());

        $tenant = (string) TenantContext::id();
        $response = app(OverviewController::class)->index($tenant, $request);

        return (array) ($response->getData(true)['data'] ?? []);
    }
}
