<?php

declare(strict_types=1);

namespace Hypervel\Support;

class HigherOrderTapProxy
{
    /**
     * Create a new tap proxy instance.
     */
    public function __construct(
        public mixed $target,
    ) {
    }

    /**
     * Dynamically pass method calls to the target.
     */
    public function __call(string $method, array $parameters): mixed
    {
        $this->target->{$method}(...$parameters);

        return $this->target;
    }
}
