<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Guzzle;

class TransportState
{
    public int $active = 0;

    /**
     * Record the native coroutine reserving a multi-handler.
     */
    public function __construct(public readonly int $owner)
    {
    }
}
