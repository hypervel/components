<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Server\Resource;

class LastLogLineResource extends Resource
{
    /**
     * Return the resource description.
     */
    public function description(): string
    {
        return 'The last line of the log file';
    }

    /**
     * Return the last log line.
     */
    public function handle(): string
    {
        return '2025-07-02 12:00:00 Error: Something went wrong.';
    }
}
