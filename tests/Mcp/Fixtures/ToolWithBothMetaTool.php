<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Tool;

class ToolWithBothMetaTool extends Tool
{
    protected string $description = 'This tool returns a response with both content-level and result-level meta';

    /**
     * Return separate content and result metadata.
     */
    public function handle(Request $request): ResponseFactory
    {
        return Response::make([
            Response::text('First response')->withMeta(['content_index' => 1]),
            Response::text('Second response')->withMeta(['content_index' => 2]),
        ])->withMeta([
            'result_key' => 'result_value',
            'total_responses' => 2,
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
