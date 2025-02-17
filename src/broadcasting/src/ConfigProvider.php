<?php

declare(strict_types=1);

namespace SwooleTW\Hyperf\Broadcasting;

use SwooleTW\Hyperf\Broadcasting\Contracts\Factory;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Factory::class => BroadcastManager::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration file of broadcast.',
                    'source' => __DIR__ . '/../publish/broadcasting.php',
                    'destination' => BASE_PATH . '/config/autoload/broadcasting.php',
                ],
            ],
        ];
    }
}
