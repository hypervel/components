<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Tools;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class ErrorHandlingTool extends Tool
{
    protected string $name = 'test_error_handling';

    protected string $description = 'Tests error response handling';

    /**
     * Return the deliberate tool failure.
     */
    public function handle(Request $request): Response
    {
        return Response::error('This tool intentionally returns an error for testing');
    }
}
