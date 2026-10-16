<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Server\Tool;

class CurrentTimeTool extends Tool
{
    protected string $description = 'This tool gets the current time';

    /**
     * Return the current time from the injected clock.
     */
    public function handle(Clock $clock): string
    {
        return 'The current time is ' . $clock->now();
    }
}
