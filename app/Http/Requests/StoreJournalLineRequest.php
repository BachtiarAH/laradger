<?php

namespace App\Http\Requests;

use App\Models\JournalLine;
use App\Rules\LeafAccount;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJournalLineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', JournalLine::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'journal_id' => ['required', 'uuid', Rule::exists('journals', 'id')->where('tenant_id', TenantContext::id())],
            'account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id()), new LeafAccount],
            'debit' => ['required_without:credit', 'nullable', 'numeric', 'min:0'],
            'credit' => ['required_without:debit', 'nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'account_id' => 'akun',
            'journal_id' => 'jurnal',
        ];
    }
}
