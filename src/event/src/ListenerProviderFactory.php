<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Event\ListenerData;
use Psr\Container\ContainerInterface;

class ListenerProviderFactory
{
    public function __invoke(ContainerInterface $container): ListenerProvider
    {
        $listenerProvider = new ListenerProvider();

        // Register config listeners.
        $this->registerConfig($listenerProvider, $container);

        // Register annotation listeners.
        $this->registerAnnotations($listenerProvider, $container);

        return $listenerProvider;
    }

    protected function registerConfig(ListenerProvider $provider, ContainerInterface $container): void
    {
        $config = $container->get(ConfigInterface::class);
        foreach ($config->get('listeners', []) as $listener => $priority) {
            if (is_int($listener)) {
                $listener = $priority;
                $priority = ListenerData::DEFAULT_PRIORITY;
            }
            if (is_string($listener)) {
                $this->register($provider, $container, $listener, $priority);
            }
        }
    }

    protected function registerAnnotations(ListenerProvider $provider, ContainerInterface $container): void
    {
        foreach (AnnotationCollector::list() as $className => $values) {
            /** @var Listener $annotation */
            if ($annotation = $values['_c'][Listener::class] ?? null) {
                $this->register($provider, $container, $className, $annotation->priority);
            }
        }
    }

    protected function register(ListenerProvider $provider, ContainerInterface $container, string $listener, int $priority = ListenerData::DEFAULT_PRIORITY): void
    {
        $instance = $container->get($listener);
        if ($instance instanceof ListenerInterface) {
            foreach ($instance->listen() as $event) {
                $provider->on($event, [$instance, 'process'], $priority);
            }
        }
    }
}
