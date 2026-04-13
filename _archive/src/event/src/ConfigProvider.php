<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Event\Contracts\ListenerProvider;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Dispatcher::class => EventDispatcherFactory::class,
                ListenerProvider::class => ListenerProviderFactory::class,
            ],
        ];
    }
}
