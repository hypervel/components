<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Closure;
use Hypervel\Context\NonCopyableContext;
use WeakMap;

/**
 * Keep async transports in their owning coroutine, including when children copy context.
 */
class PusherHandlerContext implements NonCopyableContext
{
    /** @var WeakMap<Closure, callable> */
    public readonly WeakMap $handlers;

    /**
     * Create a coroutine-local collection of client handlers.
     */
    public function __construct()
    {
        $this->handlers = new WeakMap;
    }
}
