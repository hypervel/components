<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Promptable;

class ConversationalAgent implements Agent, Conversational
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that responds extremely concisely to all queries.';
    }

    /**
     * Get the list of messages comprising the conversation so far.
     */
    public function messages(): iterable
    {
        return [
            new Message(role: 'user', content: 'My name is Taylor Otwell'),
        ];
    }
}
