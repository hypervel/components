<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                //
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for Sanctum.',
                    'source' => __DIR__ . '/../publish/sanctum.php',
                    'destination' => BASE_PATH . '/config/autoload/sanctum.php',
                ],
            ],
        ];
    }
}
