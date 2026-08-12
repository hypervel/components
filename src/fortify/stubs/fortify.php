<?php

declare(strict_types=1);

use Hypervel\Fortify\Features;

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Guard and Middleware
    |--------------------------------------------------------------------------
    |
    | These values define the guard and middleware Fortify uses for its routes.
    | When null, Fortify follows the current request guard. Set a guard name to
    | pin Fortify's built-in routes to that guard.
    |
    */

    'middleware' => ['web'],

    'guard' => null,

    'auth_middleware' => 'auth',

    /*
    |--------------------------------------------------------------------------
    | Username and Email
    |--------------------------------------------------------------------------
    |
    | These values identify the login and password-reset fields. Usernames may
    | also be normalized to lowercase before authentication or persistence.
    |
    */

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    /*
    |--------------------------------------------------------------------------
    | Fortify Views
    |--------------------------------------------------------------------------
    |
    | Disable view routes when the application provides its own frontend.
    |
    */

    'views' => true,

    /*
    |--------------------------------------------------------------------------
    | Home Path and Redirects
    |--------------------------------------------------------------------------
    |
    | Fortify uses these destinations after successful authentication actions.
    | Null feature redirects fall back to the home path.
    |
    */

    'home' => '/home',

    'redirects' => [
        'login' => null,
        'logout' => null,
        'password-confirmation' => null,
        'register' => null,
        'email-verification' => null,
        'password-reset' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Prefix, Domain, and Paths
    |--------------------------------------------------------------------------
    |
    | Customize Fortify's route group or replace individual route paths here.
    | Null path values retain the conventional Fortify path.
    |
    */

    'prefix' => '',

    'domain' => null,

    'paths' => [
        'login' => null,
        'logout' => null,
        'password' => [
            'request' => null,
            'reset' => null,
            'email' => null,
            'update' => null,
            'confirm' => null,
            'confirmation' => null,
        ],
        'register' => null,
        'verification' => [
            'notice' => null,
            'verify' => null,
            'send' => null,
        ],
        'user-profile-information' => [
            'update' => null,
        ],
        'user-password' => [
            'update' => null,
        ],
        'two-factor' => [
            'login' => null,
            'enable' => null,
            'confirm' => null,
            'disable' => null,
            'qr-code' => null,
            'secret-key' => null,
            'recovery-codes' => null,
        ],
        'passkey' => [
            'login-options' => null,
            'login' => null,
            'confirm-options' => null,
            'confirm' => null,
            'registration-options' => null,
            'store' => null,
            'destroy' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiters
    |--------------------------------------------------------------------------
    |
    | These values select the rate limiters used by Fortify's public endpoints.
    |
    */

    'limiters' => [
        'login' => 'login',
        'two-factor' => '5,1',
        'passkeys' => 'passkeys',
    ],

    /*
    |--------------------------------------------------------------------------
    | Passkeys
    |--------------------------------------------------------------------------
    |
    | These settings connect Fortify to Hypervel's passkey support.
    |
    */

    'passkeys' => [
        'relying_party_id' => env('PASSKEYS_RELYING_PARTY_ID', parse_url(config('app.url'), PHP_URL_HOST)),
        'allowed_origins' => env_array('PASSKEYS_ALLOWED_ORIGINS', [config('app.url')]),
        'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),
        'timeout' => (int) env('PASSKEYS_TIMEOUT', 60000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Remove features from this array to disable their routes and behavior.
    |
    */

    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        // Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
            'secret-length' => 32,
            // 'window' => 0,
        ]),
        Features::passkeys([
            'confirmPassword' => true,
        ]),
    ],
];
