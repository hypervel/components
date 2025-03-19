<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Closure;
use Hyperf\Context\ApplicationContext;

class PoolProxy
{
    protected ObjectPool $pool;

    public function __construct(
        protected string $name,
        protected Closure $resolver,
        protected array $options = [],
        protected ?Closure $releaseCallback = null,
    ) {
        $this->pool = ApplicationContext::getContainer()
            ->get(PoolFactory::class)
            ->get(
                $this->name,
                $this->resolver,
                $this->options
            );
    }

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
