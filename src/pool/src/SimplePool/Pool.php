<?php

declare(strict_types=1);

namespace Hypervel\Pool\SimplePool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Pool\Pool as AbstractPool;

/**
 * A simple pool that creates connections via a callback.
 */
class Pool extends AbstractPool
{
    /**
     * @var callable
     */
    protected $callback;

    /**
     * @param array<string, mixed> $option
     */
    public function __construct(
        Container $container,
        callable $callback,
        array $option
    ) {
        $this->callback = $callback;

        parent::__construct($container, $option);
    }

    protected function createConnection(): ConnectionInterface
    {
        return new Connection($this->container, $this, $this->callback);
    }
}
