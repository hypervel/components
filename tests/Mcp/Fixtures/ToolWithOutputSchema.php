<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Tool;

class ToolWithOutputSchema extends Tool
{
    protected string $description = 'This tool returns user data with output schema';

    /**
     * Return structured user data.
     */
    public function handle(Request $request): ResponseFactory
    {
        return Response::structured([
            'id' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
    }

    /**
     * Return the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Return the tool's output schema.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('User ID')->required(),
            'name' => $schema->string()->description('User name')->required(),
            'email' => $schema->string()->description('User email'),
        ];
    }
}
