<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class MicrosoftRedirectController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return Socialite::driver('microsoft')->redirect();
    }
}
