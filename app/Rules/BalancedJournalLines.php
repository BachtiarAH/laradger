<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class BalancedJournalLines implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $totalDebitCents = 0;
        $totalCreditCents = 0;

        foreach ((array) $value as $line) {
            if (! is_array($line)) {
                continue;
            }

            $totalDebitCents += self::toCents($line['debit'] ?? null);
            $totalCreditCents += self::toCents($line['credit'] ?? null);
        }

        if ($totalDebitCents !== $totalCreditCents) {
            $fail('The :attribute must balance: total debits must equal total credits.');
        }
    }

    private static function toCents(mixed $amount): int
    {
        if ($amount === null || ! is_numeric((string) $amount)) {
            return 0;
        }

        return (int) round(((float) $amount) * 100);
    }
}
