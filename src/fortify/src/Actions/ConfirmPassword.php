<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Actions;

use Closure;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Fortify;

class ConfirmPassword
{
    /**
     * Confirm that the given password is valid for the given user.
     */
    public function __invoke(StatefulGuard $guard, Authenticatable&Model $user, ?string $password = null): bool
    {
        $username = Fortify::username();

        return Fortify::confirmPasswordsUsingCallback() instanceof Closure
            ? $this->confirmPasswordUsingCustomCallback($user, $password)
            : $guard->validate([
                $username => $user->{$username},
                'password' => $password,
            ]);
    }

    /**
     * Confirm the user's password using a custom callback.
     */
    protected function confirmPasswordUsingCustomCallback(Authenticatable&Model $user, ?string $password = null): bool
    {
        $callback = Fortify::confirmPasswordsUsingCallback();

        return $callback instanceof Closure && (bool) $callback($user, $password);
    }
}
