<?php

declare(strict_types=1);

use Hypervel\Fortify\Features;
use Hypervel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Hypervel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Hypervel\Fortify\Http\Controllers\ConfirmedPasswordStatusController;
use Hypervel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Hypervel\Fortify\Http\Controllers\EmailVerificationNotificationController;
use Hypervel\Fortify\Http\Controllers\EmailVerificationPromptController;
use Hypervel\Fortify\Http\Controllers\NewPasswordController;
use Hypervel\Fortify\Http\Controllers\PasswordController;
use Hypervel\Fortify\Http\Controllers\PasswordResetLinkController;
use Hypervel\Fortify\Http\Controllers\ProfileInformationController;
use Hypervel\Fortify\Http\Controllers\RecoveryCodeController;
use Hypervel\Fortify\Http\Controllers\RegisteredUserController;
use Hypervel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Hypervel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Hypervel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Hypervel\Fortify\Http\Controllers\TwoFactorSecretKeyController;
use Hypervel\Fortify\Http\Controllers\VerifyEmailController;
use Hypervel\Fortify\RoutePath;
use Hypervel\Passkeys\Http\Controllers\PasskeyConfirmationController;
use Hypervel\Passkeys\Http\Controllers\PasskeyLoginController;
use Hypervel\Passkeys\Http\Controllers\PasskeyRegistrationController;
use Hypervel\Support\Facades\Route;

$middleware = (array) config('fortify.middleware');
$guard = config('fortify.guard');

if (is_string($guard) && $guard !== '') {
    $middleware[] = 'auth.guard:' . $guard;
}

Route::group(['middleware' => $middleware], function () {
    $enableViews = config('fortify.views');
    $authMiddleware = config('fortify.auth_middleware');

    // Authentication...
    if ($enableViews) {
        Route::get(RoutePath::for('login', '/login'), [AuthenticatedSessionController::class, 'create'])
            ->middleware(['guest'])
            ->name('login');
    }

    $limiter = config('fortify.limiters.login');
    $twoFactorLimiter = config('fortify.limiters.two-factor');
    $passkeyLimiter = config('fortify.limiters.passkeys');
    $verificationLimiter = config('fortify.limiters.verification', '6,1');

    Route::post(RoutePath::for('login', '/login'), [AuthenticatedSessionController::class, 'store'])
        ->middleware(array_filter([
            'guest',
            $limiter ? 'throttle:' . $limiter : null,
        ]))->name('login.store');

    Route::post(RoutePath::for('logout', '/logout'), [AuthenticatedSessionController::class, 'destroy'])
        ->middleware([$authMiddleware])
        ->name('logout');

    // Password Reset...
    if (Features::enabled(Features::resetPasswords())) {
        if ($enableViews) {
            Route::get(RoutePath::for('password.request', '/forgot-password'), [PasswordResetLinkController::class, 'create'])
                ->middleware(['guest'])
                ->name('password.request');

            Route::get(RoutePath::for('password.reset', '/reset-password/{token}'), [NewPasswordController::class, 'create'])
                ->middleware(['guest'])
                ->name('password.reset');
        }

        Route::post(RoutePath::for('password.email', '/forgot-password'), [PasswordResetLinkController::class, 'store'])
            ->middleware(['guest'])
            ->name('password.email');

        Route::post(RoutePath::for('password.update', '/reset-password'), [NewPasswordController::class, 'store'])
            ->middleware(['guest'])
            ->name('password.update');
    }

    // Registration...
    if (Features::enabled(Features::registration())) {
        if ($enableViews) {
            Route::get(RoutePath::for('register', '/register'), [RegisteredUserController::class, 'create'])
                ->middleware(['guest'])
                ->name('register');
        }

        Route::post(RoutePath::for('register', '/register'), [RegisteredUserController::class, 'store'])
            ->middleware(['guest'])
            ->name('register.store');
    }

    // Email Verification...
    if (Features::enabled(Features::emailVerification())) {
        if ($enableViews) {
            Route::get(RoutePath::for('verification.notice', '/email/verify'), [EmailVerificationPromptController::class, '__invoke'])
                ->middleware([$authMiddleware])
                ->name('verification.notice');
        }

        Route::get(RoutePath::for('verification.verify', '/email/verify/{id}/{hash}'), [VerifyEmailController::class, '__invoke'])
            ->middleware([$authMiddleware, 'signed', 'throttle:' . $verificationLimiter])
            ->name('verification.verify');

        Route::post(RoutePath::for('verification.send', '/email/verification-notification'), [EmailVerificationNotificationController::class, 'store'])
            ->middleware([$authMiddleware, 'throttle:' . $verificationLimiter])
            ->name('verification.send');
    }

    // Profile Information...
    if (Features::enabled(Features::updateProfileInformation())) {
        Route::put(RoutePath::for('user-profile-information.update', '/user/profile-information'), [ProfileInformationController::class, 'update'])
            ->middleware([$authMiddleware])
            ->name('user-profile-information.update');
    }

    // Passwords...
    if (Features::enabled(Features::updatePasswords())) {
        Route::put(RoutePath::for('user-password.update', '/user/password'), [PasswordController::class, 'update'])
            ->middleware([$authMiddleware])
            ->name('user-password.update');
    }

    // Password Confirmation...
    if ($enableViews) {
        Route::get(RoutePath::for('password.confirm', '/user/confirm-password'), [ConfirmablePasswordController::class, 'show'])
            ->middleware([$authMiddleware])
            ->name('password.confirm');
    }

    Route::get(RoutePath::for('password.confirmation', '/user/confirmed-password-status'), [ConfirmedPasswordStatusController::class, 'show'])
        ->middleware([$authMiddleware])
        ->name('password.confirmation');

    Route::post(RoutePath::for('password.confirm', '/user/confirm-password'), [ConfirmablePasswordController::class, 'store'])
        ->middleware([$authMiddleware])
        ->name('password.confirm.store');

    // Two Factor Authentication...
    if (Features::enabled(Features::twoFactorAuthentication())) {
        if ($enableViews) {
            Route::get(RoutePath::for('two-factor.login', '/two-factor-challenge'), [TwoFactorAuthenticatedSessionController::class, 'create'])
                ->middleware(['guest'])
                ->name('two-factor.login');
        }

        Route::post(RoutePath::for('two-factor.login', '/two-factor-challenge'), [TwoFactorAuthenticatedSessionController::class, 'store'])
            ->middleware(array_filter([
                'guest',
                $twoFactorLimiter ? 'throttle:' . $twoFactorLimiter : null,
            ]))->name('two-factor.login.store');

        $twoFactorMiddleware = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword')
            ? [$authMiddleware, 'password.confirm']
            : [$authMiddleware];

        Route::post(RoutePath::for('two-factor.enable', '/user/two-factor-authentication'), [TwoFactorAuthenticationController::class, 'store'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.enable');

        Route::post(RoutePath::for('two-factor.confirm', '/user/confirmed-two-factor-authentication'), [ConfirmedTwoFactorAuthenticationController::class, 'store'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.confirm');

        Route::delete(RoutePath::for('two-factor.disable', '/user/two-factor-authentication'), [TwoFactorAuthenticationController::class, 'destroy'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.disable');

        Route::get(RoutePath::for('two-factor.qr-code', '/user/two-factor-qr-code'), [TwoFactorQrCodeController::class, 'show'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.qr-code');

        Route::get(RoutePath::for('two-factor.secret-key', '/user/two-factor-secret-key'), [TwoFactorSecretKeyController::class, 'show'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.secret-key');

        Route::get(RoutePath::for('two-factor.recovery-codes', '/user/two-factor-recovery-codes'), [RecoveryCodeController::class, 'index'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.recovery-codes');

        Route::post(RoutePath::for('two-factor.recovery-codes', '/user/two-factor-recovery-codes'), [RecoveryCodeController::class, 'store'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.regenerate-recovery-codes');
    }

    // Passkeys...
    if (Features::enabled(Features::passkeys())) {
        $throttle = $passkeyLimiter ? ['throttle:' . $passkeyLimiter] : [];

        $passkeyAuthMiddleware = [$authMiddleware];

        $passkeyMiddleware = (bool) Features::option(Features::passkeys(), 'confirmPassword', true)
            ? [...$passkeyAuthMiddleware, 'password.confirm']
            : $passkeyAuthMiddleware;

        $passkeyGuestMiddleware = ['guest', ...$throttle];
        $passkeyConfirmMiddleware = [...$passkeyAuthMiddleware, ...$throttle];
        $passkeyManageMiddleware = [...$passkeyMiddleware, ...$throttle];

        Route::get(RoutePath::for('passkey.login-options', '/passkeys/login/options'), [PasskeyLoginController::class, 'index'])
            ->middleware($passkeyGuestMiddleware)
            ->name('passkey.login-options');

        Route::post(RoutePath::for('passkey.login', '/passkeys/login'), [PasskeyLoginController::class, 'store'])
            ->middleware($passkeyGuestMiddleware)
            ->name('passkey.login');

        Route::get(RoutePath::for('passkey.confirm-options', '/passkeys/confirm/options'), [PasskeyConfirmationController::class, 'index'])
            ->middleware($passkeyConfirmMiddleware)
            ->name('passkey.confirm-options');

        Route::post(RoutePath::for('passkey.confirm', '/passkeys/confirm'), [PasskeyConfirmationController::class, 'store'])
            ->middleware($passkeyConfirmMiddleware)
            ->name('passkey.confirm');

        Route::get(RoutePath::for('passkey.registration-options', '/user/passkeys/options'), [PasskeyRegistrationController::class, 'index'])
            ->middleware($passkeyManageMiddleware)
            ->name('passkey.registration-options');

        Route::post(RoutePath::for('passkey.store', '/user/passkeys'), [PasskeyRegistrationController::class, 'store'])
            ->middleware($passkeyManageMiddleware)
            ->name('passkey.store');

        Route::delete(RoutePath::for('passkey.destroy', '/user/passkeys/{passkey}'), [PasskeyRegistrationController::class, 'destroy'])
            ->middleware($passkeyMiddleware)
            ->name('passkey.destroy');
    }
});
