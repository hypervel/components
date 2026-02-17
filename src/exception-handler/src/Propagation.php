<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler;

use Hypervel\Support\Traits\StaticInstance;

class Propagation
{
    use StaticInstance;

    /**
     * Whether exception propagation has been stopped.
     */
    protected bool $propagationStopped = false;

    /**
     * Determine if propagation has been stopped.
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Set the propagation stopped state.
     */
    public function setPropagationStopped(bool $propagationStopped): static
    {
        $this->propagationStopped = $propagationStopped;
        return $this;
    }
}
