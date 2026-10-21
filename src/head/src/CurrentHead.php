<?php

declare(strict_types=1);

namespace Hypervel\Head;

use Hypervel\Context\ReplicableContext;

/**
 * The request-scoped head data and response status.
 */
class CurrentHead implements ReplicableContext
{
    protected HeadData $data;

    protected ?int $status = null;

    /**
     * Create the current head state.
     */
    public function __construct()
    {
        $this->flush();
    }

    /**
     * Get the current head data.
     */
    public function data(): HeadData
    {
        return $this->data;
    }

    /**
     * Get the current response status.
     */
    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * Set the current response status.
     */
    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    /**
     * Clear the current page data and response status.
     */
    public function flush(): void
    {
        $this->data = new HeadData;
        $this->status = null;
    }

    /**
     * Copy the current state for an independent coroutine.
     */
    public function replicate(): static
    {
        $replica = clone $this;
        $replica->data = $this->data->replicate();

        return $replica;
    }
}
