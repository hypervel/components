<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Tool;
use Throwable;

class ToolFailed
{
    /**
     * Create an event for a failed tool invocation.
     *
     * @param array<string, mixed> $arguments
     * @param float $time wall time spent in the tool's handler before it threw, in milliseconds
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent $agent,
        public Tool $tool,
        public array $arguments,
        public Throwable $exception,
        public float $time,
    ) {
    }
}
