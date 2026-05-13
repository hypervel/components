<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\Contracts\Container\Container;

class SimpleObjectPool extends ObjectPool
{
    /**
     * Callback function used to create new objects for the pool.
     *
     * @var callable
     */
    protected $callback;

    /**
     * Create a new SimpleObjectPool instance.
     *
     * @param Container $container The container instance
     */
    public function __construct(
        protected Container $container,
        callable $callback,
        array $config = []
    ) {
        $this->callback = $callback;

        parent::__construct($container, $config);
    }

    /**
     * Sets a new callback function for object creation.
     *
     * Boot-only. The callback persists on the pool for the worker lifetime and
     * is used to create every subsequent object in that pool. Per-request use
     * races across coroutines.
     *
     * @param callable $callback The function to create new objects
     */
    public function setCallback(callable $callback): static
    {
        $this->callback = $callback;

        return $this;
    }

    /**
     * Creates a new object using the defined callback.
     *
     * @return object The newly created object
     */
    protected function createObject(): object
    {
        return ($this->callback)();
    }
}
