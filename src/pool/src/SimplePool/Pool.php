<?php

declare(strict_types=1);

namespace Hypervel\Pool\SimplePool;

use Hyperf\Contract\ConnectionInterface;
use Hypervel\Pool\Pool as AbstractPool;
use Psr\Container\ContainerInterface;

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
        ContainerInterface $container,
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
