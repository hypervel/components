<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Resources;

use Hypervel\Context\CoroutineContext;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\Methods\ListTools;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\Fixtures\SayHiTool;
use Hypervel\Tests\Mcp\TestCase;

class ListToolsTest extends TestCase
{
    public function testReturnsAValidListToolsResponse(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-tools',
            'params' => [],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 5,
            tools: [SayHiTool::class],
            resources: [],
            prompts: [],
        );

        $listTools = new ListTools;

        $response = $listTools->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();
        $this->assertSame(1, $payload['id']);
        $this->assertEquals([
            'tools' => [
                [
                    'name' => 'say-hi-tool',
                    'description' => 'This tool says hello to a person',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'The name of the person to greet',
                            ],
                        ],
                        'required' => ['name'],
                    ],
                    'annotations' => ['title' => 'Say Hi Tool'],
                    'title' => 'Say Hi Tool',
                ],
            ],
        ], $payload['result']);
    }

    public function testHandlesPaginationCorrectly(): void
    {
        $toolClasses = $this->makeTools();

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: $toolClasses,
            resources: [],
            prompts: [],
        );

        $listTools = new ListTools;

        $firstListToolsRequest = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-tools',
            'params' => [],
        ]);

        $firstPageResponse = $listTools->handle($firstListToolsRequest, $context);

        $firstPayload = $firstPageResponse->toArray();
        $this->assertInstanceOf(JsonRpcResponse::class, $firstPageResponse);
        $this->assertSame(1, $firstPayload['id']);
        $this->assertCount(10, $firstPayload['result']['tools']);
        $this->assertArrayHasKey('nextCursor', $firstPayload['result']);
        $this->assertNotNull($firstPayload['result']['nextCursor']);
        $this->assertSame('dummy-tool1', $firstPayload['result']['tools'][0]['name']);
        $this->assertSame('dummy-tool10', $firstPayload['result']['tools'][9]['name']);

        $nextCursor = $firstPayload['result']['nextCursor'];

        $secondListToolsRequest = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'list-tools',
            'params' => ['cursor' => $nextCursor],
        ]);

        $secondPageResponse = $listTools->handle($secondListToolsRequest, $context);

        $secondPayload = $secondPageResponse->toArray();
        $this->assertInstanceOf(JsonRpcResponse::class, $secondPageResponse);
        $this->assertSame(2, $secondPayload['id']);
        $this->assertCount(2, $secondPayload['result']['tools']);
        $this->assertArrayNotHasKey('nextCursor', $secondPayload['result']);
        $this->assertSame('dummy-tool11', $secondPayload['result']['tools'][0]['name']);
        $this->assertSame('dummy-tool12', $secondPayload['result']['tools'][1]['name']);
    }

    public function testUsesDefaultPerPageWhenNotProvided(): void
    {
        $toolClasses = $this->makeTools();

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 7,
            tools: $toolClasses,
            resources: [],
            prompts: [],
        );

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-tools',
            'params' => [/* no per_page */],
        ]);

        $listTools = new ListTools;
        $response = $listTools->handle($request, $context);

        $payload = $response->toArray();
        $this->assertCount(7, $payload['result']['tools']);
        $this->assertArrayHasKey('nextCursor', $payload['result']);
    }

    public function testUsesRequestedPerPageWhenValid(): void
    {
        $toolClasses = $this->makeTools();

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: $toolClasses,
            resources: [],
            prompts: [],
        );

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-tools',
            'params' => ['per_page' => 5],
        ]);

        $listTools = new ListTools;
        $response = $listTools->handle($request, $context);

        $payload = $response->toArray();
        $this->assertCount(5, $payload['result']['tools']);
        $this->assertArrayHasKey('nextCursor', $payload['result']);
    }

    public function testCapsPerPageAtMaxPaginationLength(): void
    {
        $toolClasses = $this->makeTools();

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 7,
            defaultPaginationLength: 7,
            tools: $toolClasses,
            resources: [],
            prompts: [],
        );

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-tools',
            'params' => ['per_page' => 20],
        ]);

        $listTools = new ListTools;
        $response = $listTools->handle($request, $context);

        $payload = $response->toArray();
        $this->assertCount(7, $payload['result']['tools']);
        $this->assertArrayHasKey('nextCursor', $payload['result']);
    }

    public function testRespectsPerPageWhenBiggerThanDefault(): void
    {
        $toolClasses = $this->makeTools();

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 15,
            defaultPaginationLength: 5,
            tools: $toolClasses,
            resources: [],
            prompts: [],
        );

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-tools',
            'params' => ['per_page' => 8],
        ]);

        $listTools = new ListTools;
        $response = $listTools->handle($request, $context);

        $payload = $response->toArray();
        $this->assertCount(8, $payload['result']['tools']);
        $this->assertArrayHasKey('nextCursor', $payload['result']);
    }

    public function testReturnsEmptyListWhenTheSingleToolIsNotEligibleForRegistration(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-tools',
            'params' => [],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 5,
            tools: [new class extends SayHiTool {
                /**
                 * Reject registration of the tool.
                 */
                public function shouldRegister(): bool
                {
                    return false;
                }
            }],
            resources: [],
            prompts: [],
        );

        $listTools = new ListTools;

        $response = $listTools->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'tools' => [],
        ], $payload['result']);
    }

    public function testReturnsEmptyListWhenTheSingleToolIsNotEligibleForRegistrationViaRequest(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-tools',
            'params' => [
                'arguments' => ['register_tools' => false],
            ],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 5,
            tools: [new class extends SayHiTool {
                /**
                 * Determine tool registration from the request.
                 */
                public function shouldRegister(Request $request): bool
                {
                    return $request->get('register_tools', true);
                }
            }],
            resources: [],
            prompts: [],
        );

        $listTools = new ListTools;

        CoroutineContext::set(Request::class, $request->toRequest());
        $response = $listTools->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'tools' => [],
        ], $payload['result']);
    }

    /**
     * Create distinct tools for pagination.
     */
    private function makeTools(): array
    {
        return array_map(fn (int $number): Tool => new class($number) extends Tool {
            /**
             * Set the tool's identity.
             */
            public function __construct(int $number)
            {
                $this->name = 'dummy-tool' . $number;
                $this->description = 'Description for dummy tool ' . $number;
            }
        }, range(1, 12));
    }
}
