<?php

declare(strict_types=1);

namespace Hypervel\Log\Events;

class MessageLogged
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $level,
        public string $message,
        public array $context = []
    ) {
    }
}
