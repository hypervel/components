<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Auth\AuthManager;
use Hypervel\Auth\Contracts\UserProvider;
use Hypervel\Sanctum\Console\Commands\PruneExpired;
use Hypervel\Sanctum\Http\Middleware\AuthenticateSession;
use Hypervel\Sanctum\Http\Middleware\CheckAbilities;
use Hypervel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Psr\EventDispatcher\EventDispatcherInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'middlewares' => [
                'http' => [
                    'sanctum' => EnsureFrontendRequestsAreStateful::class,
                    'sanctum.abilities' => CheckAbilities::class,
                    'sanctum.ability' => CheckForAnyAbility::class,
                    'sanctum.auth.session' => AuthenticateSession::class,
                ],
            ],
            'listeners' => [
                \Hypervel\Sanctum\Listeners\RegisterSanctumGuard::class,
            ],
        ];
    }
}