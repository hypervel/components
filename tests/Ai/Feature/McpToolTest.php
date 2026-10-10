<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Concerns\RemembersConversations;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Tools\McpTool;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Mcp\Client\Primitives\Tool;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeMcpClient;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeMcpTool;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeMcpToolResult;
use Hypervel\Tests\Ai\TestCase;

class McpToolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Disable title generation for these tool tests.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.conversations.generate_title', false);
    }

    /**
     * Require the optional MCP client primitives.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Tool::class)) {
            $this->markTestSkipped('The optional MCP package is not installed.');
        }
    }

    public function testAgentsCanReturnMcpClientToolsDirectly(): void
    {
        $client = new FakeMcpClient;
        $mcpTool = new FakeMcpTool($client, 'search', null, 'Search records.', [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                ],
            ],
            'required' => ['query'],
        ]);

        $client->results['search'] = new FakeMcpToolResult([
            ['type' => 'text', 'text' => 'Found results.'],
        ], false);

        $agent = new class($mcpTool) implements Agent, HasTools {
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
            new ToolCall('call_123', 'mcp_tools_search', ['query' => 'hypervel']),
            'Done.',
        ]);

        $response = $agent->prompt('Search for Hypervel');

        $this->assertCount(1, $response->toolCalls);
        $this->assertCount(1, $response->toolResults);

        $this->assertSame('Found results.', $response->toolResults->first()->result);

        $this->assertSame(
            [
                ['name' => 'search', 'arguments' => ['query' => 'hypervel']],
            ],
            $client->toolCalls,
        );
    }

    public function testItRunsMcpClientToolsWhoseSchemaUsesUnrepresentableJsonSchema(): void
    {
        $client = new FakeMcpClient;

        $union = new McpTool(new FakeMcpTool($client, 'set_value', null, 'Set a value.', [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => ['string', 'number', 'boolean']],
                'mode' => ['oneOf' => [['const' => 'fast'], ['const' => 'slow']]],
            ],
            'required' => ['value'],
        ]));

        $client->results['set_value'] = new FakeMcpToolResult([
            ['type' => 'text', 'text' => 'Value set.'],
        ], false);

        $agent = new class($union) implements Agent, HasTools {
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
            new ToolCall('call_union', 'mcp_tools_set_value', ['value' => 'bug']),
            'Done.',
        ]);

        $response = $agent->prompt('Set the value');

        $this->assertCount(1, $response->toolResults);
        $this->assertSame('Value set.', $response->toolResults->first()->result);

        $this->assertSame(
            [
                ['name' => 'set_value', 'arguments' => ['value' => 'bug']],
            ],
            $client->toolCalls,
        );
    }

    public function testMcpClientToolsThatAreNotReadOnlyCanRequireApproval(): void
    {
        $client = new FakeMcpClient;

        $tools = collect([
            new FakeMcpTool($client, 'search', null, 'Search records.', ['type' => 'object'], annotations: ['readOnlyHint' => true]),
            new FakeMcpTool($client, 'delete', null, 'Delete a record.', ['type' => 'object']),
        ])->mapInto(McpTool::class)->map(fn (McpTool $tool) => ($tool->annotations()['readOnlyHint'] ?? false) ? $tool : $tool->requireApproval());

        $client->results['search'] = new FakeMcpToolResult([
            ['type' => 'text', 'text' => 'Found results.'],
        ], false);

        $agent = new class($tools->all()) implements Agent, Conversational, HasTools {
            use Promptable;
            use RemembersConversations;

            /**
             * Create an agent with MCP tools.
             */
            public function __construct(public array $mcpTools)
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
                return $this->mcpTools;
            }
        };

        $agent::fake([
            new ToolCall('call_search', 'mcp_tools_search', []),
            new ToolCall('call_delete', 'mcp_tools_delete', []),
        ]);

        $response = $agent->forUser((object) ['id' => 1])->prompt('Clean up the records');

        $this->assertSame(['call_delete'], $response->pendingApprovals->pluck('id')->all());

        $this->assertSame([
            ['name' => 'search', 'arguments' => []],
        ], $client->toolCalls);
    }
}
