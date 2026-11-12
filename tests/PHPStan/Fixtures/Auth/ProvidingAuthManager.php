<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Auth;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\UserProvider;

/**
 * An auth manager that gives every guard the same user provider.
 */
class ProvidingAuthManager extends AuthManager
{
    /**
     * Create the user provider implementation for the driver.
     */
    public function createUserProvider(?string $provider = null): ?UserProvider
    {
        return parent::createUserProvider('admins');
    }
}
