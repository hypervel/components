<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class SayHiTool extends Tool
{
    protected string $description = 'This tool says hello to a person';

    /**
     * Greet the requested person.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
            'name' => 'required|string',
        ]);

        $name = $request->get('name');

        return Response::text('Hello, ' . $name . '!');
    }

    /**
     * Define the greeting arguments.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('The name of the person to greet')
                ->required(),
        ];
    }
}
