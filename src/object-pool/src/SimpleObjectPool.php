<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Psr\Container\ContainerInterface;

class SimpleObjectPool extends ObjectPool
{
    /**
     * Callback function used to create new objects for the pool.
     *
     * @var callable
     */
    protected $callback;

    public function __construct(
        protected ContainerInterface $container,
        callable $callback,
        array $config = []
    ) {
        $this->callback = $callback;

        parent::__construct($container, $config);
    }

    /**
     * Sets a new callback function for object creation.
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
