<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Prompts\AgentPrompt;

class PromptingAgent
{
    /**
     * Create an event for an agent prompt.
     */
    public function __construct(
        public string $invocationId,
        public AgentPrompt $prompt,
    ) {
    }
}
