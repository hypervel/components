<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Resources;

use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\Methods\GetPrompt;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\Fixtures\ReviewMyCodePrompt;
use Hypervel\Tests\Mcp\Fixtures\TellMeHiPrompt;
use Hypervel\Tests\Mcp\TestCase;

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

    public function testResolvesTheHandleMethodFromTheIocContainer(): void
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
}
