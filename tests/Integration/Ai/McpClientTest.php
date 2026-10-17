<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Tools\McpTool;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Mcp\Client;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProviderExternal;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class McpClientTest extends TestCase
{
    #[DataProviderExternal(Providers::class, 'agents')]
    public function testEveryWeirdMcpToolFromTheEverythingServerWorksAcrossProviders(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);
        $this->requiresMcpServerEverything();

        $client = Client::local('npx', ['-y', '@modelcontextprotocol/server-everything'])
            ->withTimeout(60)
            ->connect();

        try {
            $tools = $client->tools();

            $this->assertNotEmpty($tools);

            foreach ($tools as $tool) {
                $this->assertIsArray((new McpTool($tool))->schema(new JsonSchemaTypeFactory));
            }

            $response = agent(
                instructions: 'You echo text for the user. Always call the echo tool with the exact text provided, then reply with the tool output verbatim.',
                tools: $tools->all(),
            )->prompt(
                "Echo the text 'hello-mcp-123' using the echo tool.",
                provider: $provider,
                model: $model,
            );

            $this->assertTrue($response->toolCalls->contains(fn (ToolCall $call): bool => $call->name === 'mcp_tools_echo'));
            $this->assertStringContainsString('hello-mcp-123', $response->text);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Require the executable used to launch the live MCP server.
     */
    protected function requiresMcpServerEverything(): void
    {
        if (! is_executable(trim((string) shell_exec('command -v npx 2>/dev/null')))) {
            $this->markTestSkipped('Missing npx — skipping live MCP server test.');
        }
    }
}
