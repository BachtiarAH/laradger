<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Tenancy\TenantContext;
use Closure;
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
            'is_header' => [
                'sometimes',
                'boolean',
                function (string $attribute, mixed $value, Closure $fail) use ($account): void {
                    $wantsHeader = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    $wantsHeader = $wantsHeader ?? (bool) $value;

                    if ($wantsHeader && $account->journalLines()->exists()) {
                        $fail('Akun ini sudah memiliki transaksi dan tidak bisa diubah menjadi akun induk. Pindahkan transaksi ke sub-akun terlebih dahulu.');
                    }

                    if (! $wantsHeader && $account->children()->exists()) {
                        $fail('Akun ini memiliki sub-akun dan tidak bisa diubah menjadi akun detail. Hapus atau pindahkan sub-akun terlebih dahulu.');
                    }
                },
            ],
            'parent_id' => [
                'nullable',
                'uuid',
                Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id()),
                Rule::notIn([$account->id]),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value) {
                        return;
                    }

                    $parent = Account::withoutGlobalScopes()->whereKey($value)->first();

                    // A detail account without journal lines is auto-promoted to an induk
                    // (kategori) when it gains children (see Account::booted), so only
                    // parents that already carry transactions are rejected here.
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
