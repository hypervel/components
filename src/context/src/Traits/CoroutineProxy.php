<?php

declare(strict_types=1);

namespace Hypervel\Context\Traits;

use Hypervel\Context\Context;
use RuntimeException;

trait CoroutineProxy
{
    /**
     * Forward a method call to the proxy target.
     */
    public function __call(string $name, array $arguments): mixed
    {
        $target = $this->getTargetObject();

        return $target->{$name}(...$arguments);
    }

    /**
     * Forward a property read to the proxy target.
     */
    public function __get(string $name): mixed
    {
        $target = $this->getTargetObject();

        return $target->{$name};
    }

    /**
     * Forward a property write to the proxy target.
     */
    public function __set(string $name, mixed $value): void
    {
        $target = $this->getTargetObject();
        $target->{$name} = $value;
    }

    /**
     * Retrieve the proxy target from coroutine context.
     */
    protected function getTargetObject(): mixed
    {
        if (! isset($this->proxyKey)) {
            throw new RuntimeException(sprintf('Missing $proxyKey property in %s.', $this::class));
        }

        return Context::get($this->proxyKey);
    }
}
