<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The button in the mobile app's login email. Mail apps only follow http(s) links, so this page hands
 * the token to the app through its deep link. Opening it uses nothing up: only the app, which also
 * holds the challenge id, can trade the token for a session.
 */
class AppEmailLoginController extends Controller
{
    public function __invoke(string $token): Response
    {
        $base = (string) config('services.mobile_app.deep_link');
        $deepLink = (str_ends_with($base, '/') ? $base : $base.'/')
            .'email-login?'.http_build_query(['token' => $token]);

        return response()
            ->view('auth.app-email-login', ['deepLink' => $deepLink])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex');
    }
}
