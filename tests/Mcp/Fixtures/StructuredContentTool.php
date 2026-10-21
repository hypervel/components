<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Tool;

class StructuredContentTool extends Tool
{
    protected string $description = 'This tool returns structured content';

    /**
     * Return structured weather data.
     */
    public function handle(Request $request): ResponseFactory
    {
        return Response::structured([
            'temperature' => 22.5,
            'conditions' => 'Partly cloudy',
            'humidity' => 65,
        ]);
    }

    /**
     * Define the tool arguments.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
