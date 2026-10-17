<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Tool;

class ResponseFactoryWithStructuredContentTool extends Tool
{
    protected string $description = 'This tool returns a ResponseFactory with structured content';

    /**
     * Return text and structured content together.
     */
    public function handle(Request $request): ResponseFactory
    {
        return Response::make([
            Response::text('Processing complete with status: success'),
        ])->withStructuredContent([
            'status' => 'success',
            'code' => 200,
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
