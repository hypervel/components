<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Auth\Factory as AuthFactoryContract;
use Hypervel\Contracts\Auth\Guard;

/**
 * Get auth guard or auth manager.
 */
function auth(?string $guard = null): AuthFactoryContract|Guard
{
    $auth = ApplicationContext::getContainer()
        ->get(AuthManager::class);

    if (is_null($guard)) {
        return $auth;
    }

    return $auth->guard($guard);
}
