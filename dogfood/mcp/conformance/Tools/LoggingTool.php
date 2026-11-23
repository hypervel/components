<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Tools;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class LoggingTool extends Tool
{
    protected string $name = 'test_logging_tool';

    protected string $description = 'Tests that no log notifications are sent without a requested log level';

    /**
     * Complete without sending log notifications.
     */
    public function handle(Request $request): Response
    {
        return Response::text('Logging tool completed.');
    }
}
