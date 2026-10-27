<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Queue;

use Closure;

interface JobLifecycleCallbacks
{
    /**
     * Register a callback to run before the job becomes available for another attempt.
     *
     * The release must not proceed if a callback throws.
     */
    public function beforeReleasing(Closure $callback): static;

    /**
     * Register a callback to run before the job is recorded as failed.
     *
     * The failure must not be published if a callback throws.
     */
    public function beforeFailing(Closure $callback): static;
}
