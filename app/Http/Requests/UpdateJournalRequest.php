<?php

namespace App\Http\Requests;

use App\Rules\BalancedJournalLines;
use App\Rules\LeafAccount;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJournalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('journal'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $journal = $this->route('journal');

        return [
            'transaction_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255', Rule::unique('journals', 'reference')->where('tenant_id', TenantContext::id())->ignore($journal->id)],
            'status' => ['required', Rule::in(['draft', 'posted', 'archived'])],
            // 'system' is allowed here (unlike store) because reversals and
            // template-generated journals are created with source='system' as
            // drafts and must remain editable — the UI echoes the source back.
            'source' => ['required', Rule::in(['manual', 'imported', 'system'])],
            'lines' => ['nullable', 'array', 'min:1', new BalancedJournalLines],
            'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id()), new LeafAccount],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['uuid', Rule::exists('tags', 'id')->where('tenant_id', TenantContext::id())],
        ];
    }

    public function attributes(): array
    {
        return [
            'lines.*.account_id' => 'akun',
            'lines.*.debit' => 'debit',
            'lines.*.credit' => 'kredit',
        ];
    }
}
