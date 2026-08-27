<?php

declare(strict_types=1);

use Hypervel\Fortify\Features;

/** @var null|string $appUrl */
$appUrl = config('app.url');
$defaultRelyingPartyId = $appUrl === null ? null : parse_url($appUrl, PHP_URL_HOST);
$defaultAllowedOrigins = $appUrl === null ? [] : [$appUrl];

return [
    'middleware' => ['web'],
    'guard' => null,
    'auth_middleware' => 'auth',
    'username' => 'email',
    'email' => 'email',
    'views' => true,
    'home' => '/home',
    'prefix' => '',
    'domain' => null,
    'lowercase_usernames' => false,

    /*
    |--------------------------------------------------------------------------
    | Rate Limiters
    |--------------------------------------------------------------------------
    |
    | Email verification is always rate limited. Two-factor challenges use
    | Fortify's account-scoped limiter by default. Other limiters may be null.
    |
    */

    'limiters' => [
        'login' => null,
        'two-factor' => 'two-factor',
        'passkeys' => null,
        'verification' => '6,1',
    ],
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
    | Passkeys
    |--------------------------------------------------------------------------
    |
    | These settings connect Fortify to Hypervel's passkey support. A null
    | relying party ID or user handle secret, and an empty origins list, are
    | rejected when a WebAuthn operation first needs the corresponding value.
    |
    */

    'passkeys' => [
        'relying_party_id' => env('PASSKEYS_RELYING_PARTY_ID', $defaultRelyingPartyId),
        'allowed_origins' => env_array('PASSKEYS_ALLOWED_ORIGINS', $defaultAllowedOrigins),
        'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),
        'timeout' => (int) env('PASSKEYS_TIMEOUT', 60_000),
    ],
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication(),
        Features::passkeys(),
    ],
];
