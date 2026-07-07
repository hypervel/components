<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

use InvalidArgumentException;

interface PasswordBrokerFactory
{
    /**
     * Get a password broker instance by name.
     */
    public function broker(?string $name = null): PasswordBroker;

    /**
     * Resolve the password broker name declared by the given guard.
     *
     * @throws InvalidArgumentException
     */
    public function resolveBrokerNameForGuard(string $guard): ?string;
}
