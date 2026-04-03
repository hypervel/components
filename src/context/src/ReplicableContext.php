<?php

declare(strict_types=1);

namespace Hypervel\Context;

/**
 * Marks objects stored in coroutine context that need deep-copying
 * when context is copied between coroutines.
 *
 * Without this, CoroutineContext::copyFrom() shares object references
 * between parent and child coroutines, causing mutations in one to
 * affect the other.
 */
interface ReplicableContext
{
    /**
     * Create an independent copy with the same state.
     */
    public function replicate(): static;
}
