<?php

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Mockery\MockInterface;
use SocialiteProviders\Microsoft\MicrosoftUser;
use SocialiteProviders\Microsoft\Provider;

test('the login screen offers microsoft authentication', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Continue with Microsoft')
        ->assertDontSee('or use a local account')
        ->assertDontSee('Sign in with a passkey')
        ->assertDontSee('Forgot your password?');
});

test('users can begin microsoft authentication', function () {
    $provider = microsoftProviderMock();
    $provider->shouldReceive('redirect')
        ->once()
        ->andReturn(new RedirectResponse('https://login.microsoftonline.com/example'));

    Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($provider);

    $this->get(route('auth.microsoft.redirect'))
        ->assertRedirect('https://login.microsoftonline.com/example');
});

test('microsoft authentication links an existing user by email', function () {
    config()->set('services.microsoft.tenant', 'cf3aa268-3435-4590-87af-9fb552032e29');
    $user = User::factory()->create(['email' => 'employee@choicemfg.parts']);
    mockMicrosoftCallback('cf3aa268-3435-4590-87af-9fb552032e29');

    $this->get(route('auth.microsoft.callback'))
        ->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticatedAs($user);
    $user->refresh();

    expect($user->microsoft_tenant_id)->toBe('cf3aa268-3435-4590-87af-9fb552032e29')
        ->and($user->microsoft_object_id)->toBe('ee452d5d-70c6-48f1-b278-2453bc47dc91')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('microsoft authentication provisions a new non-admin user', function () {
    config()->set('services.microsoft.tenant', 'cf3aa268-3435-4590-87af-9fb552032e29');
    mockMicrosoftCallback('cf3aa268-3435-4590-87af-9fb552032e29');

    $this->get(route('auth.microsoft.callback'))
        ->assertRedirect(route('home', absolute: false));

    $user = User::query()->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Microsoft Employee')
        ->and($user->email)->toBe('employee@choicemfg.parts')
        ->and($user->is_admin)->toBeFalse()
        ->and(Hash::check('password', $user->password))->toBeFalse();
});

test('microsoft authentication rejects users from another tenant', function () {
    config()->set('services.microsoft.tenant', 'cf3aa268-3435-4590-87af-9fb552032e29');
    mockMicrosoftCallback('277e1434-b065-42f7-904a-f8379788e1ef');

    $this->get(route('auth.microsoft.callback'))->assertForbidden();

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

function mockMicrosoftCallback(string $tenantId): void
{
    $provider = microsoftProviderMock();
    $microsoftUser = (new MicrosoftUser)->map([
        'id' => 'ee452d5d-70c6-48f1-b278-2453bc47dc91',
        'name' => 'Microsoft Employee',
        'email' => 'Employee@ChoiceMfg.parts',
    ]);

    $provider->shouldReceive('user')->once()->andReturn($microsoftUser);
    $provider->shouldReceive('getClaims')->once()->andReturn((object) ['tid' => $tenantId]);
    Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($provider);
}

function microsoftProviderMock(): Provider&MockInterface
{
    /** @var Provider&MockInterface $provider */
    $provider = Mockery::mock(Provider::class);

    return $provider;
}
