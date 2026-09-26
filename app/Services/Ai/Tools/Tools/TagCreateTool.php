<?php

namespace App\Services\Ai\Tools\Tools;

use App\Http\Controllers\Api\TagController;
use App\Http\Requests\StoreTagRequest;
use App\Services\Ai\Tools\AbstractAiTool;
use App\Services\Ai\Tools\AiToolKind;
use App\Services\Ai\Tools\Support\FormRequestInvoker;
use App\Tenancy\TenantContext;

class TagCreateTool extends AbstractAiTool
{
    public function __construct(
        private readonly FormRequestInvoker $requests,
    ) {}

    public function name(): string
    {
        return 'tag_create';
    }

    public function description(): string
    {
        return 'Propose a new tag for labelling and filtering journals, e.g. "groceries" of type vendor, '
            .'or "monthly" of type recurring.';
    }

    public function kind(): AiToolKind
    {
        return AiToolKind::Write;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Tag name.'],
                'type' => [
                    'type' => 'string',
                    'enum' => ['priority', 'recurring', 'vendor', 'tax', 'transfer'],
                    'description' => 'What the tag is used for.',
                ],
            ],
            'required' => ['name', 'type'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function title(array $arguments): string
    {
        return sprintf(
            'Create %s tag "%s"',
            $this->stringArgument($arguments, 'type') ?: 'new',
            $this->stringArgument($arguments, 'name') ?: 'unnamed',
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function payload(array $arguments): array
    {
        return [
            'name' => $this->stringArgument($arguments, 'name'),
            'type' => $this->stringArgument($arguments, 'type'),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $request = $this->requests->make(
            StoreTagRequest::class,
            $this->payload($arguments),
            [],
            ['REQUEST_URI' => '/tags'],
        );

        $response = app(TagController::class)->store((string) TenantContext::id(), $request);

        return (array) ($response->getData(true)['data'] ?? []);
    }
}
