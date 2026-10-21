<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Tool;

class InvokingTool
{
    /**
     * Create an event for a tool invocation.
     *
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent $agent,
        public Tool $tool,
        public array $arguments,
    ) {
    }
}
