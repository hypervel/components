<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Promptable;

class HistoricalReasoningWithoutToolCallsAgent implements Agent, Conversational
{
    use Promptable;

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    /**
     * Get the agent's provider.
     */
    public function provider(): string
    {
        return 'deepseek';
    }

    /**
     * Get the conversation's messages.
     */
    public function messages(): iterable
    {
        return [
            new UserMessage('What is 4+4?'),
            new AssistantMessage(
                'The answer is 8.',
                replayBlocks: [['type' => 'reasoning', 'reasoning_content' => 'Let me think... 4+4 = 8.']]
            ),
        ];
    }
}
