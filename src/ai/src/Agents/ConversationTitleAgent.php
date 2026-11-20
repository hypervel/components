<?php

declare(strict_types=1);

namespace Hypervel\Ai\Agents;

use Hypervel\Ai\Attributes\UseCheapestModel;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Promptable;

/**
 * @phpstan-consistent-constructor
 */
#[UseCheapestModel]
class ConversationTitleAgent implements Agent
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'Generate a concise 3-5 word title for a conversation that starts with the following message. Use the same language as the message. Respond with only the title, no quotes or punctuation.';
    }
}
