<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Server\Resource;

class DailyPlanResource extends Resource
{
    /**
     * Get the resource description.
     */
    public function description(): string
    {
        return 'The plan for the day';
    }

    /**
     * Handle the resource request.
     */
    public function handle(): string
    {
        // Dummy markdown content representing the daily plan.
        return "# Daily Plan\n\n- [ ] Task 1\n- [ ] Task 2\n- [ ] Task 3";
    }

    /**
     * Get the resource URI.
     */
    public function uri(): string
    {
        return 'file://resources/daily-plan.md';
    }

    /**
     * Get the resource MIME type.
     */
    public function mimeType(): string
    {
        return 'text/markdown';
    }
}
