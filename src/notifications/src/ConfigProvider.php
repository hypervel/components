<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Contracts\Notifications\Dispatcher as NotificationDispatcher;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                NotificationDispatcher::class => ChannelManager::class,
            ],
            'publish' => [
                [
                    'id' => 'resources',
                    'description' => 'The resources for notifications.',
                    'source' => __DIR__ . '/../publish/resources/views/',
                    'destination' => BASE_PATH . '/storage/view/notifications/',
                ],
            ],
        ];
    }
}
