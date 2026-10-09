<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Prompts\AgentPrompt;
use Throwable;

class AgentFailed
{
    /**
     * Create an event for a failed agent invocation.
     */
    public function __construct(
        public string $invocationId,
        public AgentPrompt $prompt,
        public Throwable $exception,
    ) {
    }
}
