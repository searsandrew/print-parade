<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticateMicrosoftUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Microsoft\Provider;

final class MicrosoftCallbackController extends Controller
{
    public function __invoke(Request $request, AuthenticateMicrosoftUser $authenticate): RedirectResponse
    {
        /** @var Provider $provider */
        $provider = Socialite::driver('microsoft');
        $microsoftUser = $provider->user();
        $claims = $provider->getClaims();
        $tenantId = property_exists($claims, 'tid') ? (string) $claims->tid : '';
        $configuredTenantId = (string) config('services.microsoft.tenant');

        abort_unless(
            $tenantId !== '' && hash_equals($configuredTenantId, $tenantId),
            403,
            'This Microsoft account does not belong to the configured organization.',
        );

        $objectId = (string) $microsoftUser->getId();
        $email = (string) $microsoftUser->getEmail();
        $name = (string) $microsoftUser->getName();

        abort_if($objectId === '' || $email === '' || $name === '', 403, 'Microsoft did not provide the required account identity.');

        $user = $authenticate->handle($tenantId, $objectId, $name, $email);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home', absolute: false));
    }
}
