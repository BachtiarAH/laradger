<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AllocateAllocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('allocate', $this->route('allocation'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
        ];
    }
}
