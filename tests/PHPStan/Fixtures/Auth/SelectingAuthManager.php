<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Auth;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\StatefulGuard;
use UnitEnum;

/**
 * An auth manager that selects its guards in its own way.
 */
class SelectingAuthManager extends AuthManager
{
    /**
     * Attempt to get the guard from the local cache.
     */
    public function guard(UnitEnum|string|null $name = null): Guard|StatefulGuard
    {
        return parent::guard('api');
    }
}
