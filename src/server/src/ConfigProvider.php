<?php

declare(strict_types=1);

namespace Hypervel\Server;

use Hypervel\Server\Command\StartServer;
use Hypervel\Server\Listener\AfterWorkerStartListener;
use Hypervel\Server\Listener\InitProcessTitleListener;
use Swoole\Server as SwooleServer;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                SwooleServer::class => SwooleServerFactory::class,
            ],
            'listeners' => [
                AfterWorkerStartListener::class,
                InitProcessTitleListener::class,
            ],
            'commands' => [
                StartServer::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for server.',
                    'source' => __DIR__ . '/../publish/server.php',
                    'destination' => BASE_PATH . '/config/autoload/server.php',
                ],
            ],
        ];
    }
}
