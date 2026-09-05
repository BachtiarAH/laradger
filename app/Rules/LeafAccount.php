<?php

namespace App\Rules;

use App\Models\Account;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class LeafAccount implements ValidationRule
{
    /**
     * Validate that the given account_id refers to a leaf account (is_header=false).
     *
     * Header (parent) accounts must not be used on journal lines.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $account = Account::withoutGlobalScopes()->whereKey($value)->first();

        if (! $account) {
            // Let the exists rule handle missing accounts.
            return;
        }

        if ((bool) $account->is_header) {
            $name = $account->name ?: $account->code;
            $fail('Akun "'.$name.'" adalah akun induk — tidak bisa dipakai transaksi.');
        }
    }
}
