<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hyperf\Context\ApplicationContext;
use Hypervel\Auth\Contracts\FactoryContract;
use Hypervel\Auth\Contracts\Guard;

/**
 * Get auth guard or auth manager.
 */
function auth(?string $guard = null): FactoryContract|Guard
{
    $auth = ApplicationContext::getContainer()
        ->get(AuthManager::class);

    if (is_null($guard)) {
        return $auth;
    }

    return $auth->guard($guard);
}
