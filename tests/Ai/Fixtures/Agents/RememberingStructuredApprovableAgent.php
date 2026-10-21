<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Concerns\RemembersConversations;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Tests\Ai\Fixtures\Tools\ApprovableNumberGenerator;

class RememberingStructuredApprovableAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;
    use RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that always uses the available tool to generate a number.';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [new ApprovableNumberGenerator];
    }

    /**
     * Get the structured output's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->integer()->required(),
        ];
    }
}
