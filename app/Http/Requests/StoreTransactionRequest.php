<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['expense', 'income', 'transfer', 'debt_payment'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:255'],
            'transaction_date' => ['sometimes', 'date'],
            'reference' => ['nullable', 'string', 'max:255', Rule::unique('journals', 'reference')->where('tenant_id', TenantContext::id())],
            'status' => ['sometimes', Rule::in(['draft', 'posted'])],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['uuid', Rule::exists('tags', 'id')->where('tenant_id', TenantContext::id())],

            // expense — unified hutang: cash pakai asset, credit pakai liability
            'payment_method' => ['sometimes', Rule::in(['cash', 'credit'])],
            'asset_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],
            'expense_account_id' => ['required_if:type,expense', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],

            // income
            'income_account_id' => ['required_if:type,income', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],

            // transfer
            'from_account_id' => ['required_if:type,transfer', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],
            'to_account_id' => ['required_if:type,transfer', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],
            'via_account_ids' => ['nullable', 'array', 'max:5'],
            'via_account_ids.*' => ['uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],

            // debt — liability untuk expense credit & debt_payment
            'liability_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where('tenant_id', TenantContext::id())],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $data = $validator->getData();
            $type = $data['type'] ?? null;
            $method = $data['payment_method'] ?? 'cash';

            if ($type === 'expense') {
                if ($method === 'credit') {
                    if (empty($data['liability_account_id'])) {
                        $validator->errors()->add('liability_account_id', 'Hutang wajib dipilih untuk pengeluaran hutang.');
                    }
                    if (empty($data['expense_account_id'])) {
                        $validator->errors()->add('expense_account_id', 'Kategori expense wajib dipilih.');
                    }
                } else {
                    if (empty($data['asset_account_id'])) {
                        $validator->errors()->add('asset_account_id', 'Sumber dana (asset) wajib dipilih untuk tunai.');
                    }
                    if (empty($data['expense_account_id'])) {
                        $validator->errors()->add('expense_account_id', 'Kategori expense wajib dipilih.');
                    }
                }
            }

            if ($type === 'income' && empty($data['asset_account_id'])) {
                $validator->errors()->add('asset_account_id', 'Akun asset penerima wajib dipilih.');
            }

            if ($type === 'debt_payment') {
                if (empty($data['asset_account_id'])) {
                    $validator->errors()->add('asset_account_id', 'Sumber dana (asset) wajib dipilih.');
                }
                if (empty($data['liability_account_id'])) {
                    $validator->errors()->add('liability_account_id', 'Akun hutang wajib dipilih.');
                }
            }

            if ($type !== 'expense' && isset($data['payment_method'])) {
                $validator->errors()->add('payment_method', 'payment_method hanya untuk tipe expense.');
            }
        });
    }
}
