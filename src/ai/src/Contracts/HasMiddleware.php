<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

interface HasMiddleware
{
    /**
     * Get the middleware wrapping each generation step of the agent.
     *
     * @return array<int, object|string>
     */
    public function middleware(): array;
}
