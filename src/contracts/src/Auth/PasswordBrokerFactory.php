<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

interface PasswordBrokerFactory
{
    /**
     * Get a password broker instance by name.
     */
    public function broker(?string $name = null): PasswordBroker;
}
