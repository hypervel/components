<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Resources;

use Hypervel\Context\CoroutineContext;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\Methods\CallTool;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\Fixtures\CurrentTimeTool;
use Hypervel\Tests\Mcp\Fixtures\SayHiTool;
use Hypervel\Tests\Mcp\Fixtures\SayHiTwiceTool;
use Hypervel\Tests\Mcp\TestCase;

class CallToolTest extends TestCase
{
    public function testReturnsAValidCallToolResponse(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'say-hi-tool',
                'arguments' => ['name' => 'John Doe'],
            ],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: [SayHiTool::class],
            resources: [],
            prompts: [],
        );

        $method = new CallTool;

        CoroutineContext::set(Request::class, $request->toRequest());
        $response = $method->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);

        $payload = $response->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Hello, John Doe!',
                ],
            ],
            'isError' => false,
        ], $payload['result']);
    }

    public function testReturnsAValidCallToolResponseThatContainsTwoMessages(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'say-hi-twice-tool',
                'arguments' => ['name' => 'John Doe'],
            ],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: [SayHiTwiceTool::class],
            resources: [],
            prompts: [],
        );

        $method = new CallTool;

        CoroutineContext::set(Request::class, $request->toRequest());
        $responses = $method->handle($request, $context);

        [$response] = iterator_to_array($responses);

        $payload = $response->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Hello, John Doe!',
                ],
                [
                    'type' => 'text',
                    'text' => 'Hello again, John Doe!',
                ],
            ],
            'isError' => false,
        ], $payload['result']);
    }

    public function testReturnsAValidCallToolResponseWithValidationError(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'say-hi-tool',
                'arguments' => ['name' => ''],
            ],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: [SayHiTool::class],
            resources: [],
            prompts: [],
        );

        $method = new CallTool;

        $response = $method->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();
        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'The name field is required.',
                ],
            ],
            'isError' => true,
        ], $payload['result']);
    }

    public function testMayResolveDependenciesOutOfTheContainer(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'current-time-tool',
                'arguments' => [],
            ],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: [CurrentTimeTool::class],
            resources: [],
            prompts: [],
        );

        $method = new CallTool;

        $response = $method->handle($request, $context);

        $payload = $response->toArray();
        ['type' => $type, 'text' => $text] = $payload['result']['content'][0];

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $this->assertSame(1, $payload['id']);
        $this->assertSame('text', $type);
        $this->assertStringContainsString('The current time is ', $text);
    }
}
