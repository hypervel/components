<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Mcp;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Attributes\Description;
use Hypervel\Mcp\Server\Tool;
use Override;

#[Description('Fetches the current weather for a city.')]
class FakeMcpServerTool extends Tool
{
    public array $invocations = [];

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $this->invocations[] = $request->all();

        return Response::text('Sunny in ' . $request->get('city') . '.');
    }

    /**
     * Get the tool schema.
     *
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()
                ->description('The city to get the weather for.')
                ->required(),
            'units' => $schema->string()
                ->enum(['celsius', 'fahrenheit'])
                ->default('celsius'),
        ];
    }
}
