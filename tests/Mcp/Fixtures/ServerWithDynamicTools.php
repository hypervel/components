<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Server;

class ServerWithDynamicTools extends Server
{
    public array $tools = [];

    /**
     * Register tools when the server starts.
     */
    protected function boot(): void
    {
        $this->tools[] = SayHiTool::class;
        $this->tools[] = StreamingTool::class;
    }
}
