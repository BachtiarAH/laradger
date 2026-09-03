<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('admin:create {email : Email of the new staff admin account} {--name= : Display name (defaults to the email local part)} {--password= : Initial password (prompted when omitted)}')]
#[Description('Create a new dedicated platform admin account. Existing user accounts are never promoted.')]
class CreateAdminUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("'{$email}' is not a valid email address.");

            return self::INVALID;
        }

        if (User::where('email', $email)->exists()) {
            $this->error(
                "An account with email '{$email}' already exists. Platform admins are dedicated staff "
                .'accounts — do not promote an existing (customer) account.'
            );

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: Str::before($email, '@'));

        $password = (string) $this->option('password');
        if ($password === '') {
            $password = (string) $this->secret('Initial password for the new admin account (min 8 characters)');
        }

        if (strlen($password) < 8) {
            $this->error('The password must be at least 8 characters.');

            return self::INVALID;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
        ]);

        $this->info("Platform admin account created for {$user->email} (id: {$user->id}).");

        return self::SUCCESS;
    }
}
