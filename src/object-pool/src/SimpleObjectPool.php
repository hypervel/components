<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Closure;
use Hypervel\Contracts\Container\Container;

class SimpleObjectPool extends ObjectPool
{
    protected Closure $callback;

    /**
     * Create a simple callback-backed object pool.
     */
    public function __construct(
        Container $container,
        callable $callback,
        PoolOptions $options,
        ?Closure $destroyCallback = null,
    ) {
        $this->callback = Closure::fromCallable($callback);

        parent::__construct($container, $options, $destroyCallback);
    }

    /**
     * Create a new object using the configured callback.
     */
    protected function createObject(): object
    {
        return ($this->callback)();
    }
}
