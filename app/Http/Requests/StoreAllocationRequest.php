<?php

namespace App\Http\Requests;

use App\Enums\AllocationStatus;
use App\Models\Allocation;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAllocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Allocation::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'target_amount' => ['nullable', 'numeric', 'decimal:0,2', 'gte:0'],
            'type' => ['sometimes', 'string', 'in:recurring,one_time'],
            'period_type' => ['sometimes', 'string', 'in:weekly,monthly,yearly,custom'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'roll_forward_mode' => ['sometimes', 'string', 'in:carry_over,release,reset'],
            'manual_realized_amount' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'status' => ['sometimes', 'string', 'in:active,upcoming,fulfilled,skipped,completed,cancelled,expired'],
            'expires_at' => ['nullable', 'date'],
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
                if (($this->input('status', AllocationStatus::Active->value)) !== AllocationStatus::Active->value) {
                    return;
                }

                $accountIds = collect($this->input('expense_account_ids', []))
                    ->filter()
                    ->unique()
                    ->values();

                if ($accountIds->isEmpty()) {
                    return;
                }

                $hasConflict = Allocation::query()
                    ->active()
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
