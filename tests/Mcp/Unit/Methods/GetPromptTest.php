<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Methods;

use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\Methods\GetPrompt;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\Fixtures\PromptWithResultMetaPrompt;
use Hypervel\Tests\Mcp\Fixtures\ReviewMyCodePrompt;
use Hypervel\Tests\Mcp\Fixtures\TellMeHiPrompt;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class GetPromptTest extends TestCase
{
    public function testReturnsAValidGetPromptResponse(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/get',
            'params' => [
                'name' => 'review-my-code-prompt',
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
            tools: [],
            resources: [],
            prompts: [ReviewMyCodePrompt::class],
        );

        $method = new GetPrompt;

        $response = $method->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();
        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'description' => 'Instructions for how to review my code',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => 'Here are the instructions on how to review my code',
                    ],
                ],
            ],
        ], $payload['result']);
    }

    public function testResolvesTheHandleMethodFromTheContainer(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/get',
            'params' => [
                'name' => 'tell-me-hi-prompt',
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
            tools: [],
            resources: [],
            prompts: [TellMeHiPrompt::class],
        );

        $method = new GetPrompt;

        $response = $method->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();
        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'description' => 'Instructions for how to tell me hi',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => 'Here are the instructions on how to tell me hi',
                    ],
                ],
            ],
        ], $payload['result']);
    }

    public function testThrowsExceptionWhenNameParameterIsMissing(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/get',
            'params' => [
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
            tools: [],
            resources: [],
            prompts: [ReviewMyCodePrompt::class],
        );

        $method = new GetPrompt;

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Missing [name] parameter.');

        $method->handle($request, $context);
    }

    public function testThrowsExceptionWhenPromptNotFound(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/get',
            'params' => [
                'name' => 'non-existent-prompt',
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
            tools: [],
            resources: [],
            prompts: [ReviewMyCodePrompt::class],
        );

        $method = new GetPrompt;

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Prompt [non-existent-prompt] not found.');

        $method->handle($request, $context);
    }

    public function testPassesArgumentsToPromptHandler(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/get',
            'params' => [
                'name' => 'review-my-code-prompt',
                'arguments' => ['test_arg' => 'test_value'],
            ],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: [],
            resources: [],
            prompts: [ReviewMyCodePrompt::class],
        );

        $method = new GetPrompt;

        $response = $method->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();
        $this->assertSame(1, $payload['id']);
        $this->assertArrayHasKey('description', $payload['result']);
        $this->assertArrayHasKey('messages', $payload['result']);
    }

    public function testReturnsAPromptResultWithResultLevelMetaWhenUsingResponseFactory(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/get',
            'params' => [
                'name' => 'prompt-with-result-meta-prompt',
            ],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: [],
            resources: [],
            prompts: [PromptWithResultMetaPrompt::class],
        );

        $method = new GetPrompt;

        $response = $method->handle($request, $context);

        $payload = $response->toArray();

        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['id']);
        $this->assertPartialArray([
            '_meta' => [
                'prompt_version' => '2.0',
                'last_updated' => '2025-01-01',
            ],
            'description' => 'Prompt with result-level meta',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => 'Prompt instructions with result meta',
                        '_meta' => [
                            'key' => 'value',
                        ],
                    ],
                ],
            ],
        ], $payload['result']);
    }

    public function testThrowsInternalErrorWhenPromptHandlerThrowsAGenericException(): void
    {
        config(['app.debug' => true]);

        $prompt = new class extends Prompt {
            protected string $description = 'Failing prompt';

            /**
             * Fail the prompt invocation.
             */
            public function handle(Request $request): Response
            {
                throw new RuntimeException('Unexpected failure.');
            }
        };

        $promptClass = $prompt::class;
        app()->instance($promptClass, $prompt);

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/get',
            'params' => ['name' => $prompt->name(), 'arguments' => []],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: '',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: [],
            resources: [],
            prompts: [$promptClass],
        );

        try {
            (new GetPrompt)->handle($request, $context);
            $this->fail('Expected JsonRpcException to be thrown');
        } catch (JsonRpcException $jsonRpcException) {
            $this->assertSame(-32603, $jsonRpcException->getCode());
            $this->assertStringContainsString('Unexpected failure.', $jsonRpcException->getMessage());
        }
    }

    public function testIncludesExceptionMessageInPromptErrorWhenAppDebugIsTrue(): void
    {
        config(['app.debug' => true]);

        $prompt = new class extends Prompt {
            protected string $description = 'Failing prompt';

            /**
             * Fail the prompt invocation.
             */
            public function handle(Request $request): Response
            {
                throw new RuntimeException('Debug me.');
            }
        };

        $promptClass = $prompt::class;
        app()->instance($promptClass, $prompt);

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/get',
            'params' => ['name' => $prompt->name(), 'arguments' => []],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: '',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: [],
            resources: [],
            prompts: [$promptClass],
        );

        try {
            (new GetPrompt)->handle($request, $context);
            $this->fail('Expected JsonRpcException to be thrown');
        } catch (JsonRpcException $jsonRpcException) {
            $this->assertSame(-32603, $jsonRpcException->getCode());
            $this->assertSame('Debug me.', $jsonRpcException->getMessage());
        }
    }

    public function testReturnsPlainMessageInPromptErrorWhenAppDebugIsFalse(): void
    {
        config(['app.debug' => false]);

        $prompt = new class extends Prompt {
            protected string $description = 'Failing prompt';

            /**
             * Fail the prompt invocation.
             */
            public function handle(Request $request): Response
            {
                throw new RuntimeException('Plain message only.');
            }
        };

        $promptClass = $prompt::class;
        app()->instance($promptClass, $prompt);

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/get',
            'params' => ['name' => $prompt->name(), 'arguments' => []],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: '',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: [],
            resources: [],
            prompts: [$promptClass],
        );

        try {
            (new GetPrompt)->handle($request, $context);
            $this->fail('Expected JsonRpcException to be thrown');
        } catch (JsonRpcException $jsonRpcException) {
            $this->assertSame(-32603, $jsonRpcException->getCode());
            $this->assertSame('An internal server error occurred.', $jsonRpcException->getMessage());
        }
    }

    #[DataProvider('invalidNames')]
    public function testRejectsNonStringPromptNames(mixed $name): void
    {
        $request = new JsonRpcRequest(42, 'prompts/get', ['name' => $name]);

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(-32602);
        $this->expectExceptionMessage('The [name] parameter must be a string.');

        (new GetPrompt)->handle($request, $this->getServerContext());
    }

    /**
     * Return malformed prompt names.
     */
    public static function invalidNames(): array
    {
        return [[1], [[]], [false]];
    }
}
