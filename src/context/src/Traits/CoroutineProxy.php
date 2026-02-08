<?php

declare(strict_types=1);

namespace Hypervel\Context\Traits;

use Hypervel\Context\Context;
use RuntimeException;

trait CoroutineProxy
{
    public function __call(string $name, array $arguments): mixed
    {
        $target = $this->getTargetObject();

        return $target->{$name}(...$arguments);
    }

    public function __get(string $name): mixed
    {
        $target = $this->getTargetObject();

        return $target->{$name};
    }

    public function __set(string $name, mixed $value): void
    {
        $target = $this->getTargetObject();
        $target->{$name} = $value;
    }

    protected function getTargetObject(): mixed
    {
        if (! isset($this->proxyKey)) {
            throw new RuntimeException(sprintf('Missing $proxyKey property in %s.', $this::class));
        }

        return Context::get($this->proxyKey);
    }
}
