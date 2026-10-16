<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Methods;

use Hypervel\Context\CoroutineContext;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\Methods\ListPrompts;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\Fixtures\ReviewMyCodePrompt;
use Hypervel\Tests\Mcp\TestCase;

class ListPromptsTest extends TestCase
{
    public function testReturnsAValidListPromptsResponse(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-prompts',
            'params' => [],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 5,
            tools: [],
            resources: [],
            prompts: [ReviewMyCodePrompt::class],
        );

        $listPrompts = new ListPrompts;

        $response = $listPrompts->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();
        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'prompts' => [
                [
                    'name' => 'review-my-code-prompt',
                    'title' => 'Review My Code Prompt',
                    'description' => 'Instructions for how to review my code',
                    'arguments' => [],
                ],
            ],
        ], $payload['result']);
    }

    public function testReturnsEmptyListWhenNoPromptsRegistered(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-prompts',
            'params' => [],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 5,
            tools: [],
            resources: [],
            prompts: [],
        );

        $listPrompts = new ListPrompts;

        $response = $listPrompts->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();
        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'prompts' => [],
        ], $payload['result']);
    }

    public function testReturnsEmptyListWhenTheSinglePromptIsNotEligibleForRegistration(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-prompts',
            'params' => [],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 5,
            tools: [],
            resources: [],
            prompts: [new class extends ReviewMyCodePrompt {
                /**
                 * Exclude this prompt from registration.
                 */
                public function shouldRegister(): bool
                {
                    return false;
                }
            }],
        );

        $listPrompts = new ListPrompts;

        $response = $listPrompts->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();
        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'prompts' => [],
        ], $payload['result']);
    }

    public function testReturnsEmptyListWhenTheSinglePromptIsNotEligibleForRegistrationViaRequest(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-prompts',
            'params' => [
                'arguments' => ['register_prompts' => false],
            ],
        ]);

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 5,
            tools: [],
            resources: [],
            prompts: [new class extends ReviewMyCodePrompt {
                /**
                 * Determine eligibility from the invocation request.
                 */
                public function shouldRegister(Request $request): bool
                {
                    return $request->get('register_prompts', true);
                }
            }],
        );

        $listPrompts = new ListPrompts;

        CoroutineContext::set(Request::class, $request->toRequest());
        $response = $listPrompts->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();
        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'prompts' => [],
        ], $payload['result']);
    }
}
