<?php

declare(strict_types=1);

namespace Hypervel\Sentry\State;

use Hypervel\Context\NonCopyableContext;
use Sentry\State\RuntimeContext;

/**
 * A reference-counted Sentry runtime context shared by coroutine owners.
 */
class SharedRuntimeContext implements NonCopyableContext
{
    private int $owners = 1;

    /**
     * Create a shared runtime context.
     */
    public function __construct(
        private readonly RuntimeContext $runtimeContext,
    ) {
    }

    /**
     * Return the runtime context.
     */
    public function getRuntimeContext(): RuntimeContext
    {
        return $this->runtimeContext;
    }

    /**
     * Retain another owner.
     */
    public function retain(): void
    {
        ++$this->owners;
    }

    /**
     * Release an owner and return the context after its final release.
     */
    public function release(): ?RuntimeContext
    {
        --$this->owners;

        return $this->owners === 0 ? $this->runtimeContext : null;
    }
}
