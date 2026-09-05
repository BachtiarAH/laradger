<?php

namespace App\Http\Requests;

use App\Models\Journal;
use App\Rules\BalancedJournalLines;
use App\Rules\LeafAccount;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJournalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Journal::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'transaction_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255', Rule::unique('journals', 'reference')->where('tenant_id', TenantContext::id())],
            'status' => ['required', Rule::in(['draft', 'posted', 'archived'])],
            'source' => ['required', Rule::in(['manual', 'imported'])],
            'ai_record_id' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1', new BalancedJournalLines],
            'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id()), new LeafAccount],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['uuid', Rule::exists('tags', 'id')->where('tenant_id', TenantContext::id())],
            'allocation_adjustments' => ['sometimes', 'array'],
            'allocation_adjustments.*.action' => ['required', Rule::in(['allocate', 'release'])],
            'allocation_adjustments.*.allocation_id' => ['required', 'uuid', Rule::exists('allocations', 'id')->where('tenant_id', TenantContext::id())],
            'allocation_adjustments.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],
            'allocation_adjustments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
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
