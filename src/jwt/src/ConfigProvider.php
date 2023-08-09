<?php

declare(strict_types=1);

namespace SwooleTW\Hyperf\JWT;

use SwooleTW\Hyperf\JWT\Contracts\ManagerContract;
use SwooleTW\Hyperf\JWT\JWTManager;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                ManagerContract::class => JWTManager::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for cache.',
                    'source' => __DIR__ . '/../publish/jwt.php',
                    'destination' => BASE_PATH . '/config/autoload/jwt.php',
                ],
            ],
        ];
    }
}
