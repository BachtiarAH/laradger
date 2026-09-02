<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'amount' => ['sometimes', 'numeric', 'decimal:0,2', 'gt:0'],
            'period_type' => ['sometimes', 'string', Rule::in(['custom', 'monthly'])],
            'is_recurring' => ['sometimes', 'boolean'],
            'budget_month' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'account_ids' => ['sometimes', 'array'],
            'account_ids.*' => ['uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['uuid', Rule::exists('tags', 'id')->where('tenant_id', TenantContext::id())],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $startsAt = $this->input('starts_at');
            $endsAt = $this->input('ends_at');

            if ($startsAt && $endsAt && $endsAt < $startsAt) {
                $validator->errors()->add('ends_at', 'The ends_at field must be after or equal to starts_at.');
            }
        });
    }
}
