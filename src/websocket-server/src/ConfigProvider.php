<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'listeners' => [
                Listener\InitSenderListener::class,
                Listener\OnPipeMessageListener::class,
            ],
        ];
    }
}
