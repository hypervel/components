<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hyperf\Collection\Arr;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

class ObserverManager
{
    /**
     * Observers that have been registered.
     */
    protected array $observers = [];

    public function __construct(
        protected ContainerInterface $container,
        protected ModelListener $listener
    ) {
    }

    /**
     * Register a single observer with the model.
     */
    public function register(string $modelClass, object|string $observer): void
    {
        $observerClass = $this->resolveObserverClassName($observer);
        foreach ($this->listener->getModelEvents() as $event => $eventClass) {
            if (! method_exists($observer, $event)) {
                continue;
            }

            if (isset($this->observers[$modelClass][$event][$observerClass])) {
                throw new InvalidArgumentException("Observer [{$observerClass}] is already registered for [{$modelClass}]");
            }

            $observer = $this->container->get($observerClass);
            $this->listener->register(
                $modelClass,
                $event,
                [$observer, $event]
            );
            $this->observers[$modelClass][$event][$observerClass] = $observer;
        }
    }

    /**
     * Get observers by the model and event.
     */
    public function getObservers(string $modelClass, ?string $event = null): array
    {
        if (is_string($event)) {
            return array_values($this->observers[$modelClass][$event] ?? []);
        }

        return Arr::flatten($this->observers[$modelClass] ?? []);
    }

    /**
     * Resolve the observer's class name from an object or string.
     *
     * @throws InvalidArgumentException
     */
    private function resolveObserverClassName(object|string $class): string
    {
        if (is_object($class)) {
            return get_class($class);
        }

        if (class_exists($class)) {
            return $class;
        }

        throw new InvalidArgumentException('Unable to find observer: ' . $class);
    }
}
