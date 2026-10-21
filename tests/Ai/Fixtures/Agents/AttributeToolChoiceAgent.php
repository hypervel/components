<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\ToolChoice;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;

#[ToolChoice(ToolChoice::required)]
class AttributeToolChoiceAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    /**
     * Get the agent tools.
     */
    public function tools(): iterable
    {
        return [
            new RandomNumberGenerator,
        ];
    }
}
