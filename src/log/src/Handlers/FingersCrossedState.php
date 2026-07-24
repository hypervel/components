<?php

declare(strict_types=1);

namespace Hypervel\Log\Handlers;

use Hypervel\Context\ReplicableContext;
use Monolog\LogRecord;

final class FingersCrossedState implements ReplicableContext
{
    /**
     * @param list<LogRecord> $buffer
     */
    public function __construct(
        public bool $buffering = true,
        public array $buffer = []
    ) {
    }

    /**
     * Buffered history is request-local and must not cross a context copy.
     */
    public function replicate(): static
    {
        return new self;
    }
}
