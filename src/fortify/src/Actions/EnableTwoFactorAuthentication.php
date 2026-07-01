<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Actions;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Hypervel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Hypervel\Fortify\Features;
use Hypervel\Fortify\Fortify;
use Hypervel\Fortify\RecoveryCode;
use Hypervel\Support\Collection;

class EnableTwoFactorAuthentication
{
    use DispatchesEvents;

    public function __construct(
        protected readonly TwoFactorAuthenticationProvider $provider,
        protected readonly Dispatcher $events,
    ) {
    }

    /**
     * Enable two factor authentication for the user.
     */
    public function __invoke(Authenticatable&Model $user, bool $force = false): void
    {
        if (empty($user->getAttribute('two_factor_secret')) || $force) {
            $secretLength = (int) Features::option(Features::twoFactorAuthentication(), 'secret-length', 32);

            $user->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt($this->provider->generateSecretKey($secretLength)),
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(Collection::times(8, static fn (): string => RecoveryCode::generate())->all(), JSON_THROW_ON_ERROR)),
            ])->save();

            $this->dispatchIfListening(
                $this->events,
                TwoFactorAuthenticationEnabled::class,
                static fn (): TwoFactorAuthenticationEnabled => new TwoFactorAuthenticationEnabled($user),
            );
        }
    }
}
