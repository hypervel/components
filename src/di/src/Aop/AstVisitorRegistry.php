<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Hypervel\Support\SplPriorityQueue;
use InvalidArgumentException;

/**
 * Registry of AST visitors used during proxy class generation.
 *
 * @mixin SplPriorityQueue
 */
class AstVisitorRegistry
{
    protected static ?SplPriorityQueue $queue = null;

    /**
     * @var array<int, string>
     */
    protected static array $values = [];

    /**
     * Delegate unknown static calls to the underlying priority queue.
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        $queue = static::getQueue();
        if (method_exists($queue, $name)) {
            return $queue->{$name}(...$arguments);
        }
        throw new InvalidArgumentException('Invalid method for ' . __CLASS__);
    }

    /**
     * Insert a visitor class name with optional priority.
     */
    public static function insert(string $value, int $priority = 0): bool
    {
        static::$values[] = $value;
        return static::getQueue()->insert($value, $priority);
    }

    /**
     * Determine if a visitor class has been registered.
     */
    public static function exists(string $value): bool
    {
        return in_array($value, static::$values, true);
    }

    /**
     * Flush all registered visitors.
     */
    public static function flushState(): void
    {
        static::$queue = null;
        static::$values = [];
    }

    /**
     * Get the priority queue of registered visitors.
     */
    public static function getQueue(): SplPriorityQueue
    {
        if (! static::$queue instanceof SplPriorityQueue) {
            static::$queue = new SplPriorityQueue();
        }
        return static::$queue;
    }
}
