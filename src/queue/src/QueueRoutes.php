<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use UnitEnum;

use function Hypervel\Support\enum_value;

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
            ? null
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
     * Boot-only. The route persists on the singleton registry for the worker
     * lifetime and affects every subsequent dispatch of that class.
     *
     * @param array<class-string, array{null|string|UnitEnum, null|string|UnitEnum}|string|UnitEnum>|class-string $class
     */
    public function set(array|string $class, UnitEnum|string|null $queue = null, UnitEnum|string|null $connection = null): void
    {
        $routes = is_array($class) ? $class : [$class => [$connection, $queue]];

        foreach ($routes as $from => $to) {
            $this->routes[$from] = is_array($to)
                ? array_map(
                    fn ($value) => $value instanceof UnitEnum ? (string) enum_value($value) : $value,
                    $to
                )
                : ($to instanceof UnitEnum ? (string) enum_value($to) : $to);
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
