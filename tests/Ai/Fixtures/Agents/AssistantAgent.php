<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasMiddleware;
use Hypervel\Ai\Promptable;

class AssistantAgent implements Agent, HasMiddleware
{
    use Promptable;

    protected array $middleware = [];

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that responds extremely concisely to all queries.';
    }

    /**
     * Get the agent middleware.
     */
    public function middleware(): array
    {
        return $this->middleware;
    }

    /**
     * Set the agent middleware.
     */
    public function withMiddleware(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }
}
