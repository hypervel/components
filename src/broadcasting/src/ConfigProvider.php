<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
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
