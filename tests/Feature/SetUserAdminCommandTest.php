<?php

use App\Models\User;

test('administrator access can be granted to an existing user', function () {
    $user = User::factory()->create();

    $this->artisan('users:admin', ['email' => $user->email])
        ->expectsOutputToContain("Administrator access granted to {$user->email}.")
        ->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();
});

test('administrator access can be revoked from an existing user', function () {
    $user = User::factory()->admin()->create();

    $this->artisan('users:admin', ['email' => $user->email, '--revoke' => true])
        ->expectsOutputToContain("Administrator access revoked from {$user->email}.")
        ->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeFalse();
});

test('changing administrator access fails for an unknown user', function () {
    $this->artisan('users:admin', ['email' => 'missing@example.test'])
        ->expectsOutputToContain('No user exists with the email missing@example.test.')
        ->assertFailed();
});
