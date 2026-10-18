<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Prompts\AgentPrompt;

class AgentStreamAbandoned
{
    /**
     * Create an event for an abandoned agent stream.
     */
    public function __construct(
        public string $invocationId,
        public AgentPrompt $prompt,
    ) {
    }
}
