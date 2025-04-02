<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Closure;
use Hyperf\Context\ApplicationContext;
use Hypervel\ObjectPool\Contracts\ObjectPool;

class PoolProxy
{
    /**
     * The object pool instance being proxied.
     */
    protected ObjectPool $pool;

    /**
     * Constructor for PoolProxy.
     *
     * @param string $name The name identifier for the pool
     * @param Closure $resolver The function to create new objects for the pool
     * @param array $options Configuration options for the pool
     * @param null|Closure $releaseCallback Optional callback to run before releasing objects back to the pool
     */
    public function __construct(
        protected string $name,
        protected Closure $resolver,
        protected array $options = [],
        protected ?Closure $releaseCallback = null,
    ) {
        $this->pool = ApplicationContext::getContainer()
            ->get(PoolManager::class)
            ->createPool(
                $this->name,
                $this->resolver,
                $this->options
            );
    }

    /**
     * Proxies method calls to the pooled object.
     *
     * Gets an object from the pool, calls the requested method on it,
     * and then releases the object back to the pool
     *
     * @param string $method The method name to call on the pooled object
     * @param array $args The arguments to pass to the method
     *
     * @return mixed The result of the method call
     */
    public function __call(string $method, array $args)
    {
        $driver = $this->pool->get();

        try {
            return $driver->{$method}(...$args);
        } finally {
            if ($this->releaseCallback) {
                ($this->releaseCallback)($driver);
            }
            $this->pool->release($driver);
        }
    }
}
