<?php

declare(strict_types=1);

namespace Hypervel\Log;

use Psr\Log\LoggerInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                LoggerInterface::class => LogManager::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration file of log.',
                    'source' => __DIR__ . '/../publish/logging.php',
                    'destination' => BASE_PATH . '/config/autoload/logging.php',
                ],
            ],
        ];
    }
}
