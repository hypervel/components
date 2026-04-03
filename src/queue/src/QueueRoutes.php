<?php

declare(strict_types=1);

namespace Hypervel\Queue;

class QueueRoutes
{
    /**
     * The mapping of class names to their default routes.
     *
     * @var array<class-string, array{null|string, null|string}|string>
     */
    protected array $routes = [];

    /**
     * Get the queue connection that a given queueable instance should be routed to.
     */
    public function getConnection(object $queueable): ?string
    {
        $route = $this->getRoute($queueable);

        if (is_null($route)) {
            return null;
        }

        return is_string($route)
            ? $route
            : $route[0];
    }

    /**
     * Get the queue that a given queueable instance should be routed to.
     */
    public function getQueue(object $queueable): ?string
    {
        $route = $this->getRoute($queueable);

        if (is_null($route)) {
            return null;
        }

        return is_string($route)
            ? $route
            : $route[1];
    }

    /**
     * Get the route for a given queueable instance.
     *
     * @return null|array{null|string, null|string}|string
     */
    public function getRoute(object $queueable): array|string|null
    {
        if (empty($this->routes)) {
            return null;
        }

        $classes = array_merge(
            [get_class($queueable)],
            class_parents($queueable) ?: [],
            class_implements($queueable) ?: [],
            class_uses_recursive($queueable)
        );

        foreach ($classes as $class) {
            if (isset($this->routes[$class])) {
                return $this->routes[$class];
            }
        }

        return null;
    }

    /**
     * Register the queue route for the given class.
     *
     * @param array<class-string, array{null|string, null|string}|string>|class-string $class
     */
    public function set(array|string $class, ?string $queue = null, ?string $connection = null): void
    {
        $routes = is_array($class) ? $class : [$class => [$connection, $queue]];

        foreach ($routes as $from => $to) {
            $this->routes[$from] = $to;
        }
    }

    /**
     * Get all registered queue routes.
     *
     * @return array<class-string, array{null|string, null|string}|string>
     */
    public function all(): array
    {
        return $this->routes;
    }
}
