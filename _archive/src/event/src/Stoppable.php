<?php

declare(strict_types=1);

namespace Hypervel\Event;

trait Stoppable
{
    protected bool $propagation = false;

    /**
     * Determine if propagation has been stopped.
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagation;
    }

    /**
     * Set the propagation stopped state.
     */
    public function setPropagation(bool $propagation): static
    {
        $this->propagation = $propagation;

        return $this;
    }
}
