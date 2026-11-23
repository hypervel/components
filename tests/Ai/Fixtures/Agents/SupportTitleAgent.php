<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Agents\ConversationTitleAgent;
use Hypervel\Ai\Attributes\Model;

#[Model('llama-3.1-8b-instant')]
class SupportTitleAgent extends ConversationTitleAgent
{
    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'Title this support thread in 3-6 words, naming the product area first.';
    }
}
