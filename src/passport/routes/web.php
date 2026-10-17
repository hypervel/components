<?php

declare(strict_types=1);

use Hypervel\Http\Middleware\FrameGuard;
use Hypervel\Passport\Passport;
use Hypervel\Support\Facades\Route;

Route::post('/token', [
    'uses' => 'AccessTokenController@issueToken',
    'as' => 'token',
    'middleware' => 'throttle:passport',
]);

Route::get('/authorize', [
    'uses' => 'AuthorizationController@authorize',
    'as' => 'authorizations.authorize',
    'middleware' => ['web', FrameGuard::class],
])->block();

Route::post('/authorize', [
    'uses' => 'AuthorizationController@store',
    'as' => 'authorizations.store',
]);

Route::get('/authorize/resume/{authorization}', [
    'uses' => 'AuthorizationController@resume',
    'as' => 'authorizations.resume',
    'middleware' => ['web', FrameGuard::class],
])->block();

if (Passport::$deviceCodeGrantEnabled) {
    Route::get('/device', [
        'uses' => 'DeviceUserCodeController',
        'as' => 'device',
        'middleware' => ['web', FrameGuard::class],
    ]);

    Route::post('/device/code', [
        'uses' => 'DeviceCodeController',
        'as' => 'device.code',
        'middleware' => 'throttle:passport',
    ]);
}

$guard = config('passport.guard');

Route::middleware(['web', $guard ? 'auth:' . $guard : 'auth'])->group(function (): void {
    Route::post('/token/refresh', [
        'uses' => 'TransientTokenController@refresh',
        'as' => 'token.refresh',
    ]);

    Route::post('/authorize/consent', [
        'uses' => 'ApproveAuthorizationController@approve',
        'as' => 'authorizations.approve',
        'middleware' => [FrameGuard::class],
    ])->block();

    Route::delete('/authorize/consent', [
        'uses' => 'DenyAuthorizationController@deny',
        'as' => 'authorizations.deny',
        'middleware' => [FrameGuard::class],
    ])->block();

    Route::post('/authorize/account/continue', [
        'uses' => 'AuthorizationController@continueAsCurrentAccount',
        'as' => 'authorizations.account.continue',
        'middleware' => [FrameGuard::class],
    ])->block();

    Route::post('/authorize/account/switch', [
        'uses' => 'AuthorizationController@switchAccount',
        'as' => 'authorizations.account.switch',
        'middleware' => [FrameGuard::class],
    ])->block();

    if (Passport::$deviceCodeGrantEnabled) {
        Route::get('/device/authorize', [
            'uses' => 'DeviceAuthorizationController',
            'as' => 'device.authorizations.authorize',
            'middleware' => [FrameGuard::class, 'throttle:passport'],
        ])->block();

        Route::post('/device/authorize', [
            'uses' => 'ApproveDeviceAuthorizationController',
            'as' => 'device.authorizations.approve',
            'middleware' => [FrameGuard::class],
        ])->block();

        Route::delete('/device/authorize', [
            'uses' => 'DenyDeviceAuthorizationController',
            'as' => 'device.authorizations.deny',
            'middleware' => [FrameGuard::class],
        ])->block();
    }

    // Deprecated JSON management routes are omitted; applications define their own routes.
});
