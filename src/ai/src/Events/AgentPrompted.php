<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\StreamedAgentResponse;

class AgentPrompted
{
    /**
     * Create an event for a completed agent prompt.
     */
    public function __construct(
        public string $invocationId,
        public AgentPrompt $prompt,
        public StreamedAgentResponse|AgentResponse $response
    ) {
    }
}
