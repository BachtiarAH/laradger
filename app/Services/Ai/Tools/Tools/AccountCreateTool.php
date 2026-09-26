<?php

namespace App\Services\Ai\Tools\Tools;

use App\Http\Controllers\Api\AccountController;
use App\Http\Requests\StoreAccountRequest;
use App\Services\Ai\Tools\AbstractAiTool;
use App\Services\Ai\Tools\AiToolKind;
use App\Services\Ai\Tools\Support\FormRequestInvoker;
use App\Tenancy\TenantContext;

class AccountCreateTool extends AbstractAiTool
{
    public function __construct(
        private readonly FormRequestInvoker $requests,
    ) {}

    public function name(): string
    {
        return 'account_create';
    }

    public function description(): string
    {
        return 'Propose a new chart-of-accounts entry. Use this when a transaction needs an account that '
            .'does not exist yet. Always call accounts_search first to check it is really missing, and set '
            .'is_header true for grouping categories rather than accounts that hold money.';
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
                'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Account name.'],
                'type' => [
                    'type' => 'string',
                    'enum' => ['asset', 'liability', 'equity', 'income', 'expense'],
                    'description' => 'Which statement the account belongs to.',
                ],
                'is_header' => [
                    'type' => 'boolean',
                    'description' => 'True for a grouping category that cannot hold journal lines. Defaults to false.',
                ],
                'parent_id' => self::uuidSchema('Optional parent account id, for a sub-account.'),
                'currency' => ['type' => 'string', 'maxLength' => 3, 'description' => 'ISO currency code. Defaults to USD.'],
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
        $name = $this->stringArgument($arguments, 'name');
        $type = $this->stringArgument($arguments, 'type');

        return sprintf(
            'Create %s account "%s"%s',
            $type !== '' ? $type : 'new',
            $name !== '' ? $name : 'unnamed',
            ($arguments['is_header'] ?? false) === true ? ' (grouping header)' : '',
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function payload(array $arguments): array
    {
        return [
            'code' => null,
            'name' => $this->stringArgument($arguments, 'name'),
            'type' => $this->stringArgument($arguments, 'type'),
            'is_header' => ($arguments['is_header'] ?? false) === true,
            'parent_id' => $this->stringArgument($arguments, 'parent_id') ?: null,
            'currency' => $this->stringArgument($arguments, 'currency', 'USD') ?: 'USD',
            'status' => 'active',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $request = $this->requests->make(
            StoreAccountRequest::class,
            $this->payload($arguments),
            [],
            ['REQUEST_URI' => '/accounts'],
        );

        $response = app(AccountController::class)->store((string) TenantContext::id(), $request);

        return (array) ($response->getData(true)['data'] ?? []);
    }
}
