<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Actions;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Hypervel\Fortify\Fortify;

class DisableTwoFactorAuthentication
{
    use DispatchesEvents;

    /**
     * Disable two factor authentication for the user.
     */
    public function __invoke(Authenticatable&Model $user): void
    {
        $secret = $user->getAttribute('two_factor_secret');
        $recoveryCodes = $user->getAttribute('two_factor_recovery_codes');
        $confirmedAt = $user->getAttribute('two_factor_confirmed_at');

        if (! is_null($secret)
            || ! is_null($recoveryCodes)
            || ! is_null($confirmedAt)) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
            ] + (Fortify::confirmsTwoFactorAuthentication() || ! is_null($confirmedAt) ? [
                'two_factor_confirmed_at' => null,
            ] : []))->save();

            $this->dispatchIfListening(
                TwoFactorAuthenticationDisabled::class,
                static fn (): TwoFactorAuthenticationDisabled => new TwoFactorAuthenticationDisabled($user),
            );
        }
    }
}
