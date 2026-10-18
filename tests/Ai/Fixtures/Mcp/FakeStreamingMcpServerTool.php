<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Mcp;

use Generator;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Tool;
use Override;

class FakeStreamingMcpServerTool extends Tool
{
    /**
     * Handle the tool request.
     *
     * @return Generator<int, Response|ResponseFactory|string>
     */
    public function handle(Request $request): Generator
    {
        yield Response::notification('processing/progress', ['step' => 1]);
        yield Response::text('First. ');
        yield 'Second. ';
        yield Response::make([Response::text('Third.')]);
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
