<?php

declare(strict_types=1);

namespace Hypervel\Ai\Agents;

use Hypervel\Ai\Attributes\UseCheapestModel;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Promptable;
use Hypervel\Support\Str;

#[UseCheapestModel]
final class SummarizeAgent implements Agent
{
    use Promptable;

    protected int $sentences;

    /**
     * Create an agent with the desired summary length.
     */
    public function __construct(int $sentences = 3)
    {
        $this->sentences = max(1, $sentences);
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return sprintf(
            'Summarize the given text in no more than %d %s. Respond with only the summary and nothing else.',
            $this->sentences,
            Str::plural('sentence', $this->sentences),
        );
    }
}
