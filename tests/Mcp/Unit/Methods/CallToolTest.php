<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Methods;

use Generator;
use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\Methods\CallTool;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\Fixtures\CurrentTimeTool;
use Hypervel\Tests\Mcp\Fixtures\ResourceLinkTool;
use Hypervel\Tests\Mcp\Fixtures\ResponseFactoryWithStructuredContentTool;
use Hypervel\Tests\Mcp\Fixtures\SayHiTool;
use Hypervel\Tests\Mcp\Fixtures\SayHiTwiceTool;
use Hypervel\Tests\Mcp\Fixtures\SayHiWithMetaTool;
use Hypervel\Tests\Mcp\Fixtures\StructuredContentTool;
use Hypervel\Tests\Mcp\Fixtures\StructuredContentWithMetaTool;
use Hypervel\Tests\Mcp\Fixtures\ToolWithBothMetaTool;
use Hypervel\Tests\Mcp\Fixtures\ToolWithResultMetaTool;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

        CoroutineContext::set(Request::class, $request->toRequest());
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

    public function testReturnsAValidCallToolResponseWithAuthorizationError(): void
    {
        $tool = new class extends Tool {
            protected string $description = 'Unauthorized tool';

            /**
             * Reject the tool invocation.
             */
            public function handle(Request $request): Response
            {
                throw new AuthorizationException;
            }

            /**
             * Return the tool's input schema.
             */
            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $toolClass = $tool::class;
        $this->instance($toolClass, $tool);

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool->name(),
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
            tools: [$toolClass],
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
                    'text' => 'This action is unauthorized.',
                ],
            ],
            'isError' => true,
        ], $payload['result']);
    }

    public function testReturnsAValidCallToolResponseWithAuthenticationError(): void
    {
        $tool = new class extends Tool {
            protected string $description = 'Unauthenticated tool';

            /**
             * Reject the unauthenticated invocation.
             */
            public function handle(Request $request): Response
            {
                throw new AuthenticationException('Unauthenticated.');
            }

            /**
             * Return the tool's input schema.
             */
            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $toolClass = $tool::class;
        $this->instance($toolClass, $tool);

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool->name(),
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
            tools: [$toolClass],
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
                    'text' => 'Unauthenticated.',
                ],
            ],
            'isError' => true,
        ], $payload['result']);
    }

    public function testReturnsAValidCallToolResponseWithAuthorizationErrorFromGeneratorTool(): void
    {
        $tool = new class extends Tool {
            protected string $description = 'Unauthorized generator tool';

            /**
             * Reject the invocation after its first response.
             */
            public function handle(Request $request): Generator
            {
                yield Response::text('Starting...');

                throw new AuthorizationException;
            }

            /**
             * Return the tool's input schema.
             */
            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $toolClass = $tool::class;
        $this->instance($toolClass, $tool);

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool->name(),
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
            tools: [$toolClass],
            resources: [],
            prompts: [],
        );

        $method = new CallTool;

        CoroutineContext::set(Request::class, $request->toRequest());
        $responses = $method->handle($request, $context);

        $results = iterator_to_array($responses);

        $this->assertCount(1, $results);

        $payload = $results[0]->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'This action is unauthorized.',
                ],
            ],
            'isError' => true,
        ], $payload['result']);
    }

    public function testReturnsAValidCallToolResponseWithAuthenticationErrorFromGeneratorTool(): void
    {
        $tool = new class extends Tool {
            protected string $description = 'Unauthenticated generator tool';

            /**
             * Reject the unauthenticated invocation after its first response.
             */
            public function handle(Request $request): Generator
            {
                yield Response::text('Starting...');

                throw new AuthenticationException('Unauthenticated.');
            }

            /**
             * Return the tool's input schema.
             */
            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $toolClass = $tool::class;
        $this->instance($toolClass, $tool);

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool->name(),
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
            tools: [$toolClass],
            resources: [],
            prompts: [],
        );

        $method = new CallTool;

        CoroutineContext::set(Request::class, $request->toRequest());
        $responses = $method->handle($request, $context);

        $results = iterator_to_array($responses);

        $this->assertCount(1, $results);

        $payload = $results[0]->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Unauthenticated.',
                ],
            ],
            'isError' => true,
        ], $payload['result']);
    }

    public function testIncludesResultMetaWhenResponsesProvideIt(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'say-hi-with-meta-tool',
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
            tools: [SayHiWithMetaTool::class],
            resources: [],
            prompts: [],
        );

        $method = new CallTool;

        CoroutineContext::set(Request::class, $request->toRequest());
        $response = $method->handle($request, $context);

        $payload = $response->toArray();

        $this->assertPartialArray([
            'id' => 1,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Hello, John Doe!',
                        '_meta' => [
                            'test' => 'metadata',
                        ],
                    ],
                ],
                'isError' => false,
            ],
        ], $payload);
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

        CoroutineContext::set(Request::class, $request->toRequest());
        $response = $method->handle($request, $context);

        $payload = $response->toArray();
        ['type' => $type, 'text' => $text] = $payload['result']['content'][0];

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $this->assertSame(1, $payload['id']);
        $this->assertSame('text', $type);
        $this->assertStringContainsString('The current time is ', $text);
    }

    public function testReturnsAResultWithResultLevelMetaWhenUsingResponseFactory(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'tool-with-result-meta-tool',
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
            tools: [ToolWithResultMetaTool::class],
            resources: [],
            prompts: [],
        );

        $method = new CallTool;

        CoroutineContext::set(Request::class, $request->toRequest());
        $response = $method->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);

        $payload = $response->toArray();

        $this->assertPartialArray([
            'id' => 1,
            'result' => [
                '_meta' => [
                    'session_id' => 50,
                ],
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Tool response with result meta',
                    ],
                ],
                'isError' => false,
            ],
        ], $payload);
        $this->assertArrayHasKey('session_id', $payload['result']['_meta']);
    }

    public function testSeparatesContentLevelMetaFromResultLevelMeta(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'tool-with-both-meta-tool',
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
            tools: [ToolWithBothMetaTool::class],
            resources: [],
            prompts: [],
        );

        $method = new CallTool;

        CoroutineContext::set(Request::class, $request->toRequest());
        $response = $method->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);

        $payload = $response->toArray();

        $this->assertPartialArray([
            'result' => [
                'isError' => false,
                '_meta' => [
                    'result_key' => 'result_value',
                    'total_responses' => 2,
                ],
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'First response',
                        '_meta' => ['content_index' => 1],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Second response',
                        '_meta' => ['content_index' => 2],
                    ],
                ],
            ],
        ], $payload);
    }

    public function testReturnsStructuredContentInToolResponse(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured-content-tool',
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
            tools: [StructuredContentTool::class],
            resources: [],
            prompts: [],
        );

        $method = new CallTool;

        CoroutineContext::set(Request::class, $request->toRequest());
        $response = $method->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);

        $payload = $response->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertArrayHasKey('structuredContent', $payload['result']);
        $this->assertSame([
            'temperature' => 22.5,
            'conditions' => 'Partly cloudy',
            'humidity' => 65,
        ], $payload['result']['structuredContent']);
        $this->assertCount(1, $payload['result']['content']);
        $this->assertSame('text', $payload['result']['content'][0]['type']);
        $this->assertStringContainsString('"temperature":22.5', $payload['result']['content'][0]['text']);
        $this->assertFalse($payload['result']['isError']);
    }

    public function testReturnsStructuredContentWithMetaInToolResponse(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured-content-with-meta-tool',
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
            tools: [StructuredContentWithMetaTool::class],
            resources: [],
            prompts: [],
        );

        $method = new CallTool;

        CoroutineContext::set(Request::class, $request->toRequest());
        $response = $method->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);

        $payload = $response->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertArrayHasKey('structuredContent', $payload['result']);
        $this->assertSame([
            'result' => 'The operation completed successfully',
        ], $payload['result']['structuredContent']);
        $this->assertArrayHasKey('_meta', $payload['result']);
        $this->assertSame(['requestId' => 'abc123'], $payload['result']['_meta']);
        $this->assertCount(1, $payload['result']['content']);
        $this->assertFalse($payload['result']['isError']);
    }

    public function testReturnsResponseFactoryWithStructuredContentAddedViaWithStructuredContent(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'response-factory-with-structured-content-tool',
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
            tools: [ResponseFactoryWithStructuredContentTool::class],
            resources: [],
            prompts: [],
        );

        $method = new CallTool;

        CoroutineContext::set(Request::class, $request->toRequest());
        $response = $method->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);

        $payload = $response->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertArrayHasKey('structuredContent', $payload['result']);
        $this->assertSame([
            'status' => 'success',
            'code' => 200,
        ], $payload['result']['structuredContent']);
        $this->assertCount(1, $payload['result']['content']);
        $this->assertSame('text', $payload['result']['content'][0]['type']);
        $this->assertSame('Processing complete with status: success', $payload['result']['content'][0]['text']);
        $this->assertFalse($payload['result']['isError']);
    }

    public function testThrowsAnExceptionWhenTheNameParameterIsMissing(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
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

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Missing [name] parameter.');
        $method->handle($request, $context);
    }

    public function testThrowsAnExceptionWhenTheToolIsNotFound(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'non-existent-tool',
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

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Tool [non-existent-tool] not found.');
        $method->handle($request, $context);
    }

    public function testDoesNotSetUriOnRequestWhenCallingTools(): void
    {
        $tool = new class extends Tool {
            protected string $description = 'Test tool';

            /**
             * Return the resolved request URI.
             */
            public function handle(Request $request): Response
            {
                return Response::text(json_encode($request->uri()));
            }

            /**
             * Return the tool's input schema.
             */
            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $toolClass = $tool::class;
        $this->instance($toolClass, $tool);

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool->name(),
                'arguments' => [],
            ],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test', '1.0.0'),
            instructions: '',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: [$toolClass],
            resources: [],
            prompts: [],
        );

        CoroutineContext::set(Request::class, $request->toRequest());

        $method = new CallTool;
        $payload = $method->handle($request, $context)->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertCount(1, $payload['result']['content']);
        $this->assertSame('text', $payload['result']['content'][0]['type']);
        $this->assertSame('null', $payload['result']['content'][0]['text']);
    }

    public function testReturnsAResourceLinkContentBlockInTheWirePayload(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'resource-link-tool',
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
            tools: [ResourceLinkTool::class],
            resources: [],
            prompts: [],
        );

        CoroutineContext::set(Request::class, $request->toRequest());

        $method = new CallTool;
        $payload = $method->handle($request, $context)->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'content' => [
                [
                    'type' => 'resource_link',
                    'uri' => 'file:///reports/monthly.pdf',
                    'name' => 'monthly-report',
                    'title' => 'Monthly Report',
                    'description' => 'Sales rollup by region.',
                    'mimeType' => 'application/pdf',
                    'size' => 2048,
                    'annotations' => [
                        'audience' => ['user'],
                        'priority' => 0.9,
                        'lastModified' => '2026-05-07T12:00:00Z',
                    ],
                ],
            ],
            'isError' => false,
        ], $payload['result']);
    }

    #[DataProvider('invalidNames')]
    public function testRejectsNonStringToolNames(mixed $name): void
    {
        $request = new JsonRpcRequest(42, 'tools/call', ['name' => $name]);

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(-32602);
        $this->expectExceptionMessage('The [name] parameter must be a string.');

        (new CallTool)->handle($request, $this->getServerContext());
    }

    /**
     * Return malformed tool names.
     */
    public static function invalidNames(): array
    {
        return [[1], [[]], [false]];
    }
}
