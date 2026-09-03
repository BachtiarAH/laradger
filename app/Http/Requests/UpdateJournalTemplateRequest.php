<?php

namespace App\Http\Requests;

use App\Models\JournalTemplate;
use App\Rules\BalancedJournalLines;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJournalTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('journal_template');

        return $template instanceof JournalTemplate
            && $this->user()->can('update', $template);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'period_type' => ['sometimes', Rule::in(['daily', 'weekly', 'monthly'])],
            'is_active' => ['sometimes', 'boolean'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'lines' => ['sometimes', 'array', 'min:1', new BalancedJournalLines],
            'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['uuid', Rule::exists('tags', 'id')->where('tenant_id', TenantContext::id())],
        ];
    }
}
