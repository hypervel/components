<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class SayHiWithMetaTool extends Tool
{
    protected string $description = 'This tool says hello to a person with metadata';

    protected ?array $meta = [
        'requestId' => 'abc-123',
        'source' => 'tests/fixtures',
    ];

    /**
     * Return a greeting with content metadata.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
            'name' => 'required|string',
        ]);

        $name = $request->get('name');

        return Response::text('Hello, ' . $name . '!')->withMeta([
            'test' => 'metadata',
        ]);
    }

    /**
     * Return the tool's input schema.
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
