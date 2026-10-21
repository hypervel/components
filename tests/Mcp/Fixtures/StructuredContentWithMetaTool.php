<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Tool;

class StructuredContentWithMetaTool extends Tool
{
    protected string $description = 'This tool returns structured content with meta';

    /**
     * Return structured content and result metadata.
     */
    public function handle(Request $request): ResponseFactory
    {
        return Response::structured([
            'result' => 'The operation completed successfully',
        ])->withMeta(['requestId' => 'abc123']);
    }

    /**
     * Return the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
