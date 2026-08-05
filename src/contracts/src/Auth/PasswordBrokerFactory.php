<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

use InvalidArgumentException;
use UnitEnum;

interface PasswordBrokerFactory
{
    /**
     * Get a password broker instance by name.
     */
    public function broker(UnitEnum|string|null $name = null): PasswordBroker;

    /**
     * Resolve the password broker name declared by the given guard.
     *
     * @throws InvalidArgumentException
     */
    public function resolveBrokerNameForGuard(UnitEnum|string $guard): ?string;
}
