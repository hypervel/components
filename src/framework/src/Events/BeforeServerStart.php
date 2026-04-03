<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

class BeforeServerStart
{
    /**
     * Create a new before server start event instance.
     */
    public function __construct(
        public readonly string $serverName,
    ) {
    }
}
