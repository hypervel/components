<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Contracts\HasTools;

/**
 * @phpstan-consistent-constructor
 */
class AnonymousAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /**
     * Create an ad-hoc agent with instructions, messages and tools.
     */
    public function __construct(public string $instructions, public iterable $messages, public iterable $tools)
    {
    }

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * Get the conversation messages.
     */
    public function messages(): iterable
    {
        return $this->messages;
    }

    /**
     * Get the available tools.
     */
    public function tools(): iterable
    {
        return $this->tools;
    }
}
