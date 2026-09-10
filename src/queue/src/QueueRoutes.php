<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Queue\Attributes\Queue as QueueAttribute;
use Hypervel\Support\Traits\ReadsClassAttributes;
use UnitEnum;

use function Hypervel\Support\enum_value;

class QueueRoutes
{
    use ReadsClassAttributes;

    /**
     * The mapping of class names to their default routes.
     *
     * @var array<class-string, array{null|string, null|string}|string>
     */
    protected array $routes = [];

    /**
     * The queues that have been forwarded to another queue and/or connection.
     *
     * @var array<array-key, array{null|string, null|string}>
     */
    protected array $forwards = [];

    /**
     * Get the queue connection that a given queueable instance should be routed to.
     */
    public function getConnection(object $queueable, UnitEnum|string|null $queue = null): ?string
    {
        $route = $this->getRoute($queueable);

        if (is_array($route) && $route[0] !== null) {
            return $route[0];
        }

        if (empty($this->forwards)) {
            return null;
        }

        return $this->forwardedConnection(
            $queue ?? $this->getAttributeValue($queueable, QueueAttribute::class, 'queue')
                ?? (is_string($route) ? $route : ($route[1] ?? null))
        );
    }

    /**
     * Get the connection the given queue has been forwarded to.
     */
    protected function forwardedConnection(UnitEnum|string|null $queue): ?string
    {
        if (is_null($queue)) {
            return null;
        }

        return $this->forwards[enum_value($queue)][0] ?? null;
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
     * Get the queue the given queue has been forwarded to.
     */
    public function forwardedQueue(string $queue, ?string $connection = null): string
    {
        if (! isset($this->forwards[$queue])) {
            return $queue;
        }

        [$forwardConnection, $forwardQueue] = $this->forwards[$queue];

        return is_null($forwardConnection) || $forwardConnection === $connection
            ? $forwardQueue ?? $queue
            : $queue;
    }

    /**
     * Apply only forwards explicitly scoped to the given connection.
     */
    public function forwardedQueueForConnection(string $queue, ?string $connection): string
    {
        return isset($this->forwards[$queue][0])
            ? $this->forwardedQueue($queue, $connection)
            : $queue;
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
     * Register a forward for the given queue.
     *
     * Boot-only. Forwards persist on the singleton registry for the worker
     * lifetime and affect every subsequent dispatch and queue operation.
     *
     * @param array<array-key, string|UnitEnum>|string|UnitEnum $queue
     */
    public function forward(UnitEnum|array|string $queue, UnitEnum|string|null $to = null, UnitEnum|string|null $connection = null): void
    {
        $forwards = is_array($queue) ? $queue : [enum_value($queue) => $to];

        foreach ($forwards as $from => $destination) {
            $this->forwards[$from] = [
                $connection instanceof UnitEnum ? (string) enum_value($connection) : $connection,
                $destination instanceof UnitEnum ? (string) enum_value($destination) : $destination,
            ];
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
