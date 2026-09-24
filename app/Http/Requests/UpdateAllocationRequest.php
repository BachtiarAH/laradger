<?php

namespace App\Http\Requests;

use App\Enums\AllocationStatus;
use App\Models\Allocation;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAllocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $allocation = $this->route('allocation');

        return $allocation instanceof Allocation
            && $this->user()->can('update', $allocation);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'target_amount' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gte:0'],
            'type' => ['sometimes', 'string', 'in:recurring,one_time'],
            'period_type' => ['sometimes', 'string', 'in:weekly,monthly,yearly,custom'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'roll_forward_mode' => ['sometimes', 'string', 'in:carry_over,release,reset'],
            'carry_over_amount' => ['sometimes', 'numeric', 'gte:0'],
            'manual_realized_amount' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'status' => ['sometimes', 'string', 'in:active,upcoming,fulfilled,skipped,completed,cancelled,expired'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'expense_account_ids' => ['sometimes', 'nullable', 'array'],
            'expense_account_ids.*' => [
                'uuid',
                'distinct',
                Rule::exists('accounts', 'id')
                    ->where('tenant_id', TenantContext::id())
                    ->where('type', 'expense'),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $allocation = $this->route('allocation');
                if (! $allocation instanceof Allocation) {
                    return;
                }

                $currentStatus = $allocation->status instanceof AllocationStatus
                    ? $allocation->status->value
                    : $allocation?->status;
                $status = $this->input('status', $currentStatus);

                if ($status !== AllocationStatus::Active->value) {
                    return;
                }

                $accountIds = $this->has('expense_account_ids')
                    ? collect($this->input('expense_account_ids', []))->filter()->unique()->values()
                    : $allocation?->expenseAccounts()->pluck('accounts.id') ?? collect();

                if ($accountIds->isEmpty()) {
                    return;
                }

                $hasConflict = Allocation::query()
                    ->active()
                    ->where('id', '!=', $allocation->id)
                    ->whereHas('expenseAccounts', fn ($query) => $query->whereIn('accounts.id', $accountIds))
                    ->exists();

                if ($hasConflict) {
                    $validator->errors()->add(
                        'expense_account_ids',
                        'An expense account can only belong to one active auto allocation.',
                    );
                }
            },
        ];
    }
}
