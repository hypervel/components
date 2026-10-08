<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

use Hypervel\Ai\Providers\Tools\ProviderTool;

interface HasTools
{
    /**
     * Get the tools available to the agent.
     *
     * @return list<Agent|ProviderTool|Tool>
     */
    public function tools(): iterable;
}
