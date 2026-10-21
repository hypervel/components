<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Messages\UserMessage;

interface AgentInput
{
    /**
     * Get the newest user message, if the input contains one.
     */
    public function message(): ?UserMessage;

    /**
     * Get the tool approval decisions, if the input contains any.
     */
    public function decisions(): ?Decisions;
}
