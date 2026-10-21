<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Mcp;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;
use Override;

class FakeErroringMcpServerTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        return Response::error('Something went wrong.');
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
