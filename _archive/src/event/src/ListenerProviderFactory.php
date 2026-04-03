<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Hypervel\Contracts\Container\Container;
use Hypervel\Event\Contracts\ListenerInterface;

/**
 * Factory for creating and configuring the ListenerProvider.
 *
 * Registers listeners from the 'listeners' config array. Supports Hyperf's
 * ListenerInterface pattern where listeners declare which events they handle
 * via listen() and process them via process().
 */
class ListenerProviderFactory
{
    public function __invoke(Container $container): ListenerProvider
    {
        $listenerProvider = new ListenerProvider();

        $this->registerConfig($listenerProvider, $container);

        return $listenerProvider;
    }

    /**
     * Register listeners from the 'listeners' config array.
     */
    protected function registerConfig(ListenerProvider $provider, Container $container): void
    {
        $config = $container->make('config');

        foreach ($config->get('listeners', []) as $key => $value) {
            // Support both indexed array and associative (legacy priority) format
            $listener = is_int($key) ? $value : $key;

            if (is_string($listener)) {
                $this->register($provider, $container, $listener);
            }
        }
    }

    /**
     * Register a listener class implementing ListenerInterface.
     */
    protected function register(ListenerProvider $provider, Container $container, string $listener): void
    {
        $instance = $container->make($listener);

        if ($instance instanceof ListenerInterface) {
            foreach ($instance->listen() as $event) {
                $provider->on($event, [$instance, 'process']);
            }
        }
    }
}
