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
            'dependencies' => [
                // Register factories if needed
            ],
            'commands' => [
                PruneExpired::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for Sanctum.',
                    'source' => __DIR__ . '/../publish/sanctum.php',
                    'destination' => BASE_PATH . '/config/autoload/sanctum.php',
                ],
                [
                    'id' => 'database',
                    'description' => 'The migrations for Sanctum.',
                    'source' => __DIR__ . '/../database/migrations',
                    'destination' => BASE_PATH . '/migrations',
                ],
            ],
            // Register middleware aliases
            'middlewares' => [
                'http' => [
                    // These can be used in routes
                    'sanctum' => EnsureFrontendRequestsAreStateful::class,
                    'sanctum.abilities' => CheckAbilities::class,
                    'sanctum.ability' => CheckForAnyAbility::class,
                    'sanctum.auth.session' => AuthenticateSession::class,
                ],
            ],
            // Register routes
            'routes' => [
                'http' => [
                    [
                        'path' => '/sanctum/csrf-cookie',
                        'method' => 'GET',
                        'handler' => [\Hypervel\Sanctum\Http\Controllers\CsrfCookieController::class, 'show'],
                        'options' => [
                            'middleware' => ['web'],
                            'name' => 'sanctum.csrf-cookie',
                        ],
                    ],
                ],
            ],
            // Register listeners
            'listeners' => [
                \Hypervel\Sanctum\Listeners\RegisterSanctumGuard::class,
            ],
        ];
    }
}