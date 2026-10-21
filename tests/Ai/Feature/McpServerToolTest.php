<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Tools\McpServerTool;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Mcp\Server\McpServiceProvider;
use Hypervel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeMcpServerTool;
use Hypervel\Tests\Ai\TestCase;

class McpServerToolTest extends TestCase
{
    /**
     * Register the AI and MCP providers.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [...parent::getPackageProviders($app), McpServiceProvider::class];
    }

    public function testAgentsCanReturnMcpServerToolsDirectly(): void
    {
        $serverTool = new FakeMcpServerTool;

        $agent = new class($serverTool) implements Agent, HasTools {
            use Promptable;

            /**
             * Create an agent with an MCP tool.
             */
            public function __construct(public object $tool)
            {
            }

            /**
             * Get the agent instructions.
             */
            public function instructions(): string
            {
                return 'Use available tools.';
            }

            /**
             * Get the agent tools.
             */
            public function tools(): iterable
            {
                return [$this->tool];
            }
        };

        $agent::fake([
            new ToolCall('call_123', 'fake-mcp-server-tool', ['city' => 'Paris']),
            'Done.',
        ]);

        $response = $agent->prompt('What is the weather in Paris?');

        $this->assertCount(1, $response->toolCalls);
        $this->assertCount(1, $response->toolResults);

        $this->assertSame('Sunny in Paris.', $response->toolResults->first()->result);

        $this->assertSame([['city' => 'Paris']], $serverTool->invocations);
    }

    public function testMcpServerToolsExposeTheirAnnotations(): void
    {
        $tool = new #[IsReadOnly] class extends FakeMcpServerTool {};

        $this->assertSame(['readOnlyHint' => true], (new McpServerTool($tool))->annotations());
    }
}
