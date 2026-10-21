<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class SayHiTwiceTool extends Tool
{
    protected string $description = 'This tool says hello to a person twice';

    /**
     * Return two greetings for the supplied name.
     *
     * @return list<Response>
     */
    public function handle(Request $request): array
    {
        $request->validate([
            'name' => 'required|string',
        ]);

        $name = $request->get('name');

        return [
            Response::text('Hello, ' . $name . '!'),
            Response::text('Hello again, ' . $name . '!'),
        ];
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
