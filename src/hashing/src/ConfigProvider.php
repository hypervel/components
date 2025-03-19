<?php

declare(strict_types=1);

namespace Hypervel\Hashing;

use Hypervel\Hashing\Contracts\Hasher;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Hasher::class => HashManager::class,
                'hash.driver' => fn ($container) => $container->get(Hasher::class)->driver(),
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration file of hashing.',
                    'source' => __DIR__ . '/../publish/hashing.php',
                    'destination' => BASE_PATH . '/config/autoload/hashing.php',
                ],
            ],
        ];
    }
}
