<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class ToolWithoutOutputSchema extends Tool
{
    protected string $description = 'This tool does not define an output schema';

    /**
     * Return text without an output schema.
     */
    public function handle(Request $request): Response
    {
        return Response::text('Simple text response without schema');
    }

    /**
     * Return the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
