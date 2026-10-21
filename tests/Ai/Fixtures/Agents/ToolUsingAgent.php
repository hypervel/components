<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;

class ToolUsingAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * Configure the tool used by this agent.
     */
    public function __construct(public bool $fixed = false, public bool $toolThrowsException = false)
    {
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that uses structured output and generates random numbers using the tool available to you. Always use the tool to get a cryptographically secure random number.';
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            $this->fixed
                ? new FixedNumberGenerator($this->toolThrowsException)
                : new RandomNumberGenerator($this->toolThrowsException),
        ];
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
