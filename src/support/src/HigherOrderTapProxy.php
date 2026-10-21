<?php

declare(strict_types=1);

namespace Hypervel\Support;

/**
 * @template TTarget
 */
class HigherOrderTapProxy
{
    /**
     * Create a new tap proxy instance.
     *
     * @param TTarget $target
     */
    public function __construct(
        public mixed $target,
    ) {
    }

    /**
     * Dynamically pass method calls to the target.
     *
     * @return TTarget
     */
    public function __call(string $method, array $parameters): mixed
    {
        $this->target->{$method}(...$parameters);

        return $this->target;
    }
}
