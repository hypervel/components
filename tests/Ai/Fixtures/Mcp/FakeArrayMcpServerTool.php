<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Mcp;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;
use Override;

class FakeArrayMcpServerTool extends Tool
{
    /**
     * Handle the tool request.
     *
     * @return array<int, Response|string>
     */
    public function handle(Request $request): array
    {
        return [
            Response::text('First. '),
            'Second. ',
            Response::text('Third.'),
        ];
    }

    /**
     * Get the tool schema.
     *
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
