<?php

namespace App\Console\Commands;

use App\Enums\ApiAbility;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyIssuer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('api:issue-key {email : Email of the account the key acts as} {--ability=* : Abilities to grant (default: ledger:read)} {--days= : Lifetime in days (default: config api-keys.default_days)} {--label= : Short note recorded in the key name}')]
#[Description('Issue a scoped, expiring API key for a user or an external agent.')]
class IssueApiKey extends Command
{
    public function handle(ApiKeyIssuer $issuer): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No account exists for '{$email}'.");

            return self::INVALID;
        }

        /** @var array<int, string> $requested */
        $requested = (array) $this->option('ability');

        if ($requested === []) {
            $requested = [ApiAbility::LedgerRead->value];
            $this->warn('No --ability given; issuing a read-only key.');
        }

        try {
            $token = $issuer->issue(
                $user,
                $requested,
                $this->lifetime(),
                $this->option('label') !== null ? (string) $this->option('label') : null,
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $this->warn('Copy this key now. It is not recoverable and will not be shown again.');
        $this->line('');
        $this->line($token->plainTextToken);
        $this->line('');
        $this->table(
            ['User', 'Abilities', 'Expires'],
            [[
                $user->email,
                implode(', ', $token->accessToken->abilities),
                (string) $token->accessToken->expires_at?->toDateTimeString(),
            ]],
        );

        return self::SUCCESS;
    }

    private function lifetime(): ?int
    {
        $days = $this->option('days');

        if ($days === null || $days === '') {
            return null;
        }

        if (! is_numeric($days) || (int) $days < 1) {
            throw new RuntimeException('--days must be a whole number of days.');
        }

        return (int) $days;
    }
}
