<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'budget_type' => ['sometimes', 'string', Rule::in(['income', 'expense'])],
            'period_type' => ['sometimes', 'string', Rule::in(['custom', 'monthly'])],
            'is_recurring' => ['sometimes', 'boolean'],
            'budget_month' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'starts_at' => ['required_without:budget_month', 'nullable', 'date'],
            'ends_at' => ['required_without:budget_month', 'nullable', 'date', 'after_or_equal:starts_at'],
            'account_ids' => ['sometimes', 'array'],
            'account_ids.*' => ['uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['uuid', Rule::exists('tags', 'id')->where('tenant_id', TenantContext::id())],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $accountIds = $this->input('account_ids');
            $budgetType = $this->input('budget_type', 'expense');

            if (is_array($accountIds) && count($accountIds) > 0) {
                $mismatched = Account::whereIn('id', $accountIds)
                    ->where('type', '!=', $budgetType === 'income' ? 'income' : 'expense')
                    ->exists();

                if ($mismatched) {
                    $validator->errors()->add('account_ids', 'All linked accounts must match the budget type (income/expense).');
                }
            }
        });
    }
}
