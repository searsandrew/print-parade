<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuthenticateMicrosoftUser
{
    public function handle(string $tenantId, string $objectId, string $name, string $email): User
    {
        $normalizedEmail = Str::lower(trim($email));

        return DB::transaction(function () use ($tenantId, $objectId, $name, $normalizedEmail): User {
            $user = User::query()
                ->where('microsoft_tenant_id', $tenantId)
                ->where('microsoft_object_id', $objectId)
                ->lockForUpdate()
                ->first();

            $user ??= User::query()
                ->where('email', $normalizedEmail)
                ->lockForUpdate()
                ->first();

            if ($user?->microsoft_object_id !== null && ! hash_equals($user->microsoft_object_id, $objectId)) {
                throw new AuthorizationException('This account is already linked to a different Microsoft identity.');
            }

            $user ??= new User([
                'password' => Str::random(64),
            ]);

            $user->forceFill([
                'name' => $name,
                'email' => $normalizedEmail,
                'email_verified_at' => now(),
                'microsoft_tenant_id' => $tenantId,
                'microsoft_object_id' => $objectId,
            ])->save();

            return $user;
        });
    }
}
