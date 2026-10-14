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
]);

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

    Route::post('/authorize', [
        'uses' => 'ApproveAuthorizationController@approve',
        'as' => 'authorizations.approve',
    ]);

    Route::delete('/authorize', [
        'uses' => 'DenyAuthorizationController@deny',
        'as' => 'authorizations.deny',
    ]);

    if (Passport::$deviceCodeGrantEnabled) {
        Route::get('/device/authorize', [
            'uses' => 'DeviceAuthorizationController',
            'as' => 'device.authorizations.authorize',
            'middleware' => [FrameGuard::class, 'throttle:passport'],
        ]);

        Route::post('/device/authorize', [
            'uses' => 'ApproveDeviceAuthorizationController',
            'as' => 'device.authorizations.approve',
        ]);

        Route::delete('/device/authorize', [
            'uses' => 'DenyDeviceAuthorizationController',
            'as' => 'device.authorizations.deny',
        ]);
    }

    // Deprecated JSON management routes are omitted; applications define their own routes.
});
