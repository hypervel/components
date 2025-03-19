<?php

declare(strict_types=1);

namespace Hypervel\JWT;

use Hypervel\JWT\Contracts\BlacklistContract;
use Hypervel\JWT\Contracts\ManagerContract;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                BlacklistContract::class => BlacklistFactory::class,
                ManagerContract::class => JWTManager::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for jwt.',
                    'source' => __DIR__ . '/../publish/jwt.php',
                    'destination' => BASE_PATH . '/config/autoload/jwt.php',
                ],
            ],
        ];
    }
}
