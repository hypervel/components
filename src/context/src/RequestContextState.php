<?php

declare(strict_types=1);

namespace Hypervel\Context;

/**
 * @internal coroutine-local framework caches, independent of the request's attributes
 */
class RequestContextState implements ReplicableContext
{
    /** @var array<array-key, mixed> */
    public array $values = [];

    /**
     * Copy the cache map without cloning the objects stored in it.
     */
    public function replicate(): static
    {
        return clone $this;
    }
}
