<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Actions;

use Hypervel\Auth\Events\PasswordReset;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Support\Str;

class CompletePasswordReset
{
    use DispatchesEvents;

    /**
     * Complete the password reset process for the given user.
     */
    public function __invoke(StatefulGuard $guard, Authenticatable&Model $user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();

        $this->dispatchIfListening(
            PasswordReset::class,
            static fn (): PasswordReset => new PasswordReset($user),
        );
    }
}
