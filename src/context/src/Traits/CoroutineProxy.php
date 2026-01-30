<?php

declare(strict_types=1);

namespace Hypervel\Context\Traits;

use Hypervel\Context\Context;
use RuntimeException;

/**
 * Enables transparent proxying to a coroutine-local object stored in Context.
 *
 * Classes using this trait must define a `$proxyKey` property that specifies
 * the Context key where the target object is stored.
 */
trait CoroutineProxy
{
    public function __call(string $name, array $arguments): mixed
    {
        return $this->getTargetObject()->{$name}(...$arguments);
    }

    public function __get(string $name): mixed
    {
        return $this->getTargetObject()->{$name};
    }

    public function __set(string $name, mixed $value): void
    {
        $this->getTargetObject()->{$name} = $value;
    }

    protected function getTargetObject(): mixed
    {
        if (! isset($this->proxyKey)) {
            throw new RuntimeException(sprintf('Missing $proxyKey property in %s.', $this::class));
        }

        return Context::get($this->proxyKey);
    }
}
