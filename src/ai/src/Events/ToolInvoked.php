<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Tool;

class ToolInvoked
{
    /**
     * Create an event for a completed tool invocation.
     *
     * @param array<string, mixed> $arguments
     * @param float $time wall time spent in the tool's handler, in milliseconds
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent $agent,
        public Tool $tool,
        public array $arguments,
        public mixed $result,
        public float $time,
    ) {
    }
}
