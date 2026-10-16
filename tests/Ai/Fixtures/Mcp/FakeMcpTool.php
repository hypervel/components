<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Mcp;

use Hypervel\Mcp\Client\Primitives\Tool;
use Hypervel\Mcp\Client\Schema\ToolResult;
use Override;

class FakeMcpTool extends Tool
{
    /**
     * Create a fake MCP tool.
     *
     * @param array<string, mixed> $inputSchema
     * @param null|array<string, mixed> $outputSchema
     * @param array<string, mixed> $annotations
     * @param null|array<string, mixed> $meta
     */
    public function __construct(
        protected FakeMcpClient $fakeClient,
        string $name,
        ?string $title,
        ?string $description,
        array $inputSchema,
        ?array $outputSchema = null,
        array $annotations = [],
        ?array $meta = null,
    ) {
        parent::__construct(null, $name, $title, $description, $inputSchema, $outputSchema, $annotations, $meta);
    }

    /**
     * Call the fake client.
     *
     * @param array<string, mixed> $arguments
     */
    #[Override]
    public function call(array $arguments = []): ToolResult
    {
        return $this->fakeClient->callTool($this->name, $arguments);
    }
}
