<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use Hypervel\Ai\Responses\Concerns\HasQueuedResponseCallbacks;
use Hypervel\Foundation\Bus\PendingDispatch;

/**
 * @mixin PendingDispatch
 */
class QueuedAgentResponse
{
    use HasQueuedResponseCallbacks;

    /**
     * Create a queued agent response.
     */
    public function __construct(protected PendingDispatch $dispatchable)
    {
    }

    /**
     * Proxy missing method calls to the pending dispatch instance.
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->dispatchable->{$method}(...$arguments);
    }
}
