<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('account'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'code' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'parent_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id()), Rule::notIn([$account->id])],
            'currency' => ['required', 'string', 'max:3'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
