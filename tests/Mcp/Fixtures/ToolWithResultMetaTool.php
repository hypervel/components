<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Tool;

class ToolWithResultMetaTool extends Tool
{
    protected string $description = 'This tool returns a response with result-level meta';

    /**
     * Return text with result metadata.
     */
    public function handle(Request $request): ResponseFactory
    {
        return Response::make(
            Response::text('Tool response with result meta')
        )->withMeta([
            'session_id' => 50,
        ]);
    }

    /**
     * Return the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
