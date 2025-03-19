<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Psr\Container\ContainerInterface;

class SimpleObjectPool extends ObjectPool
{
    protected $callback;

    public function __construct(
        protected ContainerInterface $container,
        callable $callback,
        array $config = []
    ) {
        $this->callback = $callback;

        parent::__construct($container, $config);
    }

    public function setCallback(callable $callback): static
    {
        $this->callback = $callback;

        return $this;
    }

    protected function createObject(): object
    {
        return ($this->callback)();
    }
}
