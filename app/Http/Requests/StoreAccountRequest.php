<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Account::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:20', Rule::unique('accounts', 'code')->where('tenant_id', TenantContext::id())],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'parent_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],
            'currency' => ['required', 'string', 'max:3'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
