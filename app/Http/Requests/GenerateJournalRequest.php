<?php

namespace App\Http\Requests;

use App\Models\JournalTemplate;
use App\Rules\BalancedJournalLines;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('journal_template');

        return $template instanceof JournalTemplate
            && $this->user()->can('generate', $template);
    }

    public function rules(): array
    {
        return [
            'transaction_date' => ['nullable', 'date'],
            'lines' => ['nullable', 'array', new BalancedJournalLines],
            'lines.*.account_id' => ['required_with:lines', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
