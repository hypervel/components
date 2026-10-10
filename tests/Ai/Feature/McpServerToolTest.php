<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Tools\McpServerTool;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeMcpServerTool;
use Hypervel\Tests\Ai\TestCase;

class McpServerToolTest extends TestCase
{
    /**
     * Require the optional MCP server tools.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Tool::class)) {
            $this->markTestSkipped('The optional MCP package is not installed.');
        }
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
