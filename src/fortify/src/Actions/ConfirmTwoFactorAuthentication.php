<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Actions;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Hypervel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Hypervel\Fortify\Fortify;
use Hypervel\Support\Facades\Date;
use Hypervel\Validation\ValidationException;

class ConfirmTwoFactorAuthentication
{
    use DispatchesEvents;

    public function __construct(
        protected readonly TwoFactorAuthenticationProvider $provider,
    ) {
    }

    /**
     * Confirm the two factor authentication configuration for the user.
     *
     * @throws ValidationException
     */
    public function __invoke(Authenticatable&Model $user, string $code): void
    {
        $secret = $user->getAttribute('two_factor_secret');

        if (! is_string($secret)
            || $secret === ''
            || $code === ''
            || ! $this->provider->verify(Fortify::currentEncrypter()->decrypt($secret), $code)) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two factor authentication code was invalid.')],
            ])->errorBag('confirmTwoFactorAuthentication');
        }

        $user->forceFill([
            'two_factor_confirmed_at' => Date::now(),
        ])->save();

        $this->dispatchIfListening(
            TwoFactorAuthenticationConfirmed::class,
            static fn (): TwoFactorAuthenticationConfirmed => new TwoFactorAuthenticationConfirmed($user),
        );
    }
}
