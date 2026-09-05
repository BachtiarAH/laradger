<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Tenancy\TenantContext;
use Closure;
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
            'is_header' => ['sometimes', 'boolean'],
            'parent_id' => [
                'nullable',
                'uuid',
                Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id()),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value) {
                        return;
                    }

                    $parent = Account::withoutGlobalScopes()->whereKey($value)->first();

                    if ($parent && (bool) $parent->is_header === false) {
                        $name = $parent->name ?: $parent->code;
                        $fail('Akun induk "'.$name.'" harus bertipe kategori. Ubah akun tersebut menjadi akun induk terlebih dahulu.');
                    }

                    if ($parent && $parent->journalLines()->exists()) {
                        $name = $parent->name ?: $parent->code;
                        $fail('Akun induk "'.$name.'" sudah memiliki transaksi dan tidak bisa memiliki sub-akun.');
                    }
                },
            ],
            'currency' => ['required', 'string', 'max:3'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
