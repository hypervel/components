<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Promptable;
use Hypervel\Tests\Ai\Fixtures\Tools\ApprovableNumberGenerator;

class StatelessApprovableAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return 'You generate numbers on request.';
    }

    /**
     * Get the agent's tools.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [new ApprovableNumberGenerator];
    }
}
