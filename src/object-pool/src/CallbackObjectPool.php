<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Closure;

class CallbackObjectPool extends ObjectPool
{
    protected Closure $createCallback;

    /**
     * Create an object pool using a construction callback.
     */
    public function __construct(
        callable $createCallback,
        PoolOptions $options,
        ?Closure $destroyCallback = null,
    ) {
        $this->createCallback = Closure::fromCallable($createCallback);

        parent::__construct($options, $destroyCallback);
    }

    /**
     * Create a new object using the configured callback.
     */
    protected function createObject(): object
    {
        return ($this->createCallback)();
    }
}
