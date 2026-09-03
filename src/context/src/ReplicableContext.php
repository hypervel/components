<?php

declare(strict_types=1);

namespace Hypervel\Context;

/**
 * Marks objects that control what is installed when coroutine context is
 * copied between coroutines.
 *
 * Without this, CoroutineContext::copyFrom() shares object references
 * between parent and child coroutines, causing mutations in one to
 * affect the other.
 */
interface ReplicableContext
{
    /**
     * Create the value to install in the copied context.
     *
     * Implementations decide which state is inherited, projected, or reset
     * and must document that choice on this method.
     */
    public function replicate(): static;
}
