<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:admin {email : The user email address} {--revoke : Remove administrator access}')]
#[Description('Grant or revoke administrator access for a user')]
class SetUserAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No user exists with the email {$email}.");

            return self::FAILURE;
        }

        $isAdmin = ! $this->option('revoke');

        $user->forceFill(['is_admin' => $isAdmin])->save();

        $action = $isAdmin ? 'granted to' : 'revoked from';
        $this->components->info("Administrator access {$action} {$user->email}.");

        return self::SUCCESS;
    }
}
