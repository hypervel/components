<?php

declare(strict_types=1);

namespace Hypervel\View;

use ArrayIterator;
use Closure;
use Hypervel\Contracts\Support\DeferringDisplayableValue;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Support\Enumerable;
use IteratorAggregate;
use Stringable;
use Traversable;

class InvokableComponentVariable implements DeferringDisplayableValue, IteratorAggregate, Stringable
{
    /**
     * Create a new variable instance.
     */
    public function __construct(
        protected Closure $callable
    ) {
    }

    /**
     * Resolve the displayable value that the class is deferring.
     */
    public function resolveDisplayableValue(): Htmlable|string
    {
        return $this->__invoke();
    }

    /**
     * Get an iterator instance for the variable.
     */
    public function getIterator(): Traversable
    {
        $result = $this->__invoke();

        return new ArrayIterator($result instanceof Enumerable ? $result->all() : $result);
    }

    /**
     * Dynamically proxy attribute access to the variable.
     */
    public function __get(string $key): mixed
    {
        return $this->__invoke()->{$key};
    }

    /**
     * Dynamically proxy method access to the variable.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->__invoke()->{$method}(...$parameters);
    }

    /**
     * Resolve the variable.
     */
    public function __invoke(): mixed
    {
        return call_user_func($this->callable);
    }

    /**
     * Resolve the variable as a string.
     */
    public function __toString(): string
    {
        return (string) $this->__invoke();
    }
}
