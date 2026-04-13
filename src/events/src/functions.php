<?php

declare(strict_types=1);

namespace Hypervel\Events;

use Closure;

/**
 * Create a new queued closure event listener.
 */
function queueable(Closure $closure): QueuedClosure
{
    return new QueuedClosure($closure);
}
