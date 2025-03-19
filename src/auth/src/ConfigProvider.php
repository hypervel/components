<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Auth\Access\GateFactory;
use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Auth\Contracts\FactoryContract;
use Hypervel\Auth\Contracts\Gate as GateContract;
use Hypervel\Auth\Contracts\Guard;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                FactoryContract::class => AuthManager::class,
                Authenticatable::class => UserResolver::class,
                Guard::class => fn ($container) => $container->get(FactoryContract::class)->guard(),
                GateContract::class => GateFactory::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for auth.',
                    'source' => __DIR__ . '/../publish/auth.php',
                    'destination' => BASE_PATH . '/config/autoload/auth.php',
                ],
            ],
        ];
    }
}
