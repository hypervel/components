<?php

declare(strict_types=1);

namespace Hypervel\Coordinator;

class CoordinatorManager
{
    /**
     * A container that is used for storing coordinators.
     *
     * @var array<string, Coordinator>
     */
    private static array $container = [];

    /**
     * Initialize a coordinator with the given identifier.
     */
    public static function initialize(string $identifier): void
    {
        self::$container[$identifier] = new Coordinator();
    }

    /**
     * Get a coordinator by its identifier, creating one if it doesn't exist.
     */
    public static function until(string $identifier): Coordinator
    {
        if (! isset(self::$container[$identifier])) {
            self::$container[$identifier] = new Coordinator();
        }

        return self::$container[$identifier];
    }

    /**
     * Remove the coordinator by the identifier to prevent memory leaks.
     */
    public static function clear(string $identifier): void
    {
        unset(self::$container[$identifier]);
    }
}
