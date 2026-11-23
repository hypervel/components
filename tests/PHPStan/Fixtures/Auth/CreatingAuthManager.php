<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Auth;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\RequestGuard;

/**
 * An auth manager that creates the guards of custom drivers in its own way.
 */
class CreatingAuthManager extends AuthManager
{
    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(string $name, array $config): RequestGuard
    {
        return new RequestGuard($name, static fn () => null, $this->app, $this->createUserProvider($config['provider'] ?? null));
    }
}
