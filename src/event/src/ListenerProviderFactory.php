<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hypervel\Contracts\Config\Repository;
use Psr\Container\ContainerInterface;

/**
 * Factory for creating and configuring the ListenerProvider.
 *
 * Registers listeners from two sources:
 * 1. Config-based: Classes listed in the 'listeners' config array
 * 2. Annotation-based: Classes with #[Listener] attribute
 *
 * Both sources support Hyperf's ListenerInterface pattern where listeners
 * declare which events they handle via listen() and process them via process().
 */
class ListenerProviderFactory
{
    public function __invoke(ContainerInterface $container): ListenerProvider
    {
        $listenerProvider = new ListenerProvider();

        $this->registerConfig($listenerProvider, $container);
        $this->registerAnnotations($listenerProvider, $container);

        return $listenerProvider;
    }

    /**
     * Register listeners from the 'listeners' config array.
     */
    protected function registerConfig(ListenerProvider $provider, ContainerInterface $container): void
    {
        $config = $container->get(Repository::class);

        foreach ($config->get('listeners', []) as $key => $value) {
            // Support both indexed array and associative (legacy priority) format
            $listener = is_int($key) ? $value : $key;

            if (is_string($listener)) {
                $this->register($provider, $container, $listener);
            }
        }
    }

    /**
     * Register listeners with #[Listener] annotation.
     */
    protected function registerAnnotations(ListenerProvider $provider, ContainerInterface $container): void
    {
        foreach (AnnotationCollector::list() as $className => $values) {
            if (isset($values['_c'][Listener::class])) {
                $this->register($provider, $container, $className);
            }
        }
    }

    /**
     * Register a listener class implementing ListenerInterface.
     */
    protected function register(ListenerProvider $provider, ContainerInterface $container, string $listener): void
    {
        $instance = $container->get($listener);

        if ($instance instanceof ListenerInterface) {
            foreach ($instance->listen() as $event) {
                $provider->on($event, [$instance, 'process']);
            }
        }
    }
}
