<?php

declare(strict_types=1);

use Hypervel\Passkeys\Http\Controllers\PasskeyConfirmationController;
use Hypervel\Passkeys\Http\Controllers\PasskeyLoginController;
use Hypervel\Passkeys\Http\Controllers\PasskeyRegistrationController;
use Hypervel\Support\Facades\Route;

$groupMiddleware = config()->array('passkeys.middleware');
$guard = config('passkeys.guard');

if (is_string($guard) && $guard !== '') {
    $groupMiddleware[] = 'auth.guard:' . $guard;
}

Route::group(['middleware' => $groupMiddleware], function () {
    $managementMiddleware = array_values(array_filter(config()->array('passkeys.management_middleware')));

    $middleware = function (string ...$middleware): array {
        $throttle = config('passkeys.throttle');

        return array_values(array_filter([...$middleware, $throttle]));
    };

    Route::get('/passkeys/login/options', [PasskeyLoginController::class, 'index'])
        ->middleware($middleware('guest'))
        ->name('passkey.login-options');

    Route::post('/passkeys/login', [PasskeyLoginController::class, 'store'])
        ->middleware($middleware('guest'))
        ->name('passkey.login');

    Route::middleware('auth')->group(function () use ($managementMiddleware, $middleware) {
        Route::get('/passkeys/confirm/options', [PasskeyConfirmationController::class, 'index'])
            ->middleware($middleware())
            ->name('passkey.confirm-options');

        Route::post('/passkeys/confirm', [PasskeyConfirmationController::class, 'store'])
            ->middleware($middleware())
            ->name('passkey.confirm');

        Route::get('/user/passkeys/options', [PasskeyRegistrationController::class, 'index'])
            ->middleware($middleware(...$managementMiddleware))
            ->name('passkey.registration-options');

        Route::post('/user/passkeys', [PasskeyRegistrationController::class, 'store'])
            ->middleware($middleware(...$managementMiddleware))
            ->name('passkey.store');

        Route::delete('/user/passkeys/{passkey}', [PasskeyRegistrationController::class, 'destroy'])
            ->middleware($managementMiddleware)
            ->name('passkey.destroy');
    });
});
