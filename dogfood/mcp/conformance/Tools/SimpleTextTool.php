<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Tools;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class SimpleTextTool extends Tool
{
    protected string $name = 'test_simple_text';

    protected string $description = 'Tests simple text content response';

    /**
     * Return the fixed tool text.
     */
    public function handle(Request $request): Response
    {
        return Response::text('This is a simple text response for testing.');
    }
}
