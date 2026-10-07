<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

#[Signature('api:revoke-key {email : Email of the account whose keys to inspect} {--id=* : Token ids to revoke} {--all : Revoke every key for the account}')]
#[Description('List an account\'s API keys, or revoke them by token id.')]
class RevokeApiKey extends Command
{
    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No account exists for '{$email}'.");

            return self::INVALID;
        }

        $keys = $this->keys($user);

        /** @var array<int, string> $ids */
        $ids = (array) $this->option('id');
        $revokeAll = (bool) $this->option('all');

        if ($ids === [] && ! $revokeAll) {
            $this->table(
                ['Id', 'Name', 'Abilities', 'Expires', 'Last used'],
                $keys->map(fn (PersonalAccessToken $token): array => [
                    $token->id,
                    $token->name,
                    implode(', ', $token->abilities ?? []),
                    $this->expiry($token),
                    $token->last_used_at?->toDateTimeString() ?? 'never',
                ])->all(),
            );

            $this->line('Pass --id=<id> to revoke one, or --all to revoke them all.');

            return self::SUCCESS;
        }

        $targets = $revokeAll
            ? $keys
            : $keys->whereIn('id', $ids);

        $unknown = collect($ids)->diff($targets->pluck('id'));

        if ($unknown->isNotEmpty()) {
            $this->warn('Not an API key on this account, so left alone: '.$unknown->implode(', '));
        }

        if ($targets->isEmpty()) {
            $this->error('No matching API keys to revoke.');

            return self::INVALID;
        }

        PersonalAccessToken::whereKey($targets->pluck('id'))->delete();

        $this->info(sprintf(
            'Revoked %d API key%s for %s.',
            $targets->count(),
            $targets->count() === 1 ? '' : 's',
            $user->email,
        ));

        return self::SUCCESS;
    }

    /**
     * An expired key still shows its date, marked, because "when does this
     * expire" and "is this already dead" are different questions and a key that
     * reads as live is the one worth catching here.
     */
    private function expiry(PersonalAccessToken $token): string
    {
        if ($token->expires_at === null) {
            return 'never';
        }

        $date = $token->expires_at->toDateTimeString();

        return $token->expires_at->isPast() ? "EXPIRED {$date}" : $date;
    }

    /**
     * Only issued keys carry the configured name prefix; the tokens `POST /login`
     * hands out do not.
     *
     * Revoking "all keys" must not be able to reach the token someone is
     * currently using to drive the web app, so the two kinds are told apart by
     * name. Abilities would be the wrong signal: a login token carries Sanctum's
     * `*`, which is a statement about capability, not about what the credential
     * is for.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    private function keys(User $user): Collection
    {
        $prefix = (string) config('api-keys.name_prefix');

        return $user->tokens()
            ->where('name', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->get();
    }
}
