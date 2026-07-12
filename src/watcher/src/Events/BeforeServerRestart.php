<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Events;

class BeforeServerRestart
{
    /**
     * Create a new event instance.
     */
    public function __construct(public readonly int $pid)
    {
    }
}
