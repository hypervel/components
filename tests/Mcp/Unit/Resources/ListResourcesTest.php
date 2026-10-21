<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Resources;

use Hypervel\Context\CoroutineContext;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\Methods\ListResources;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\TestCase;

class ListResourcesTest extends TestCase
{
    public function testReturnsAValidEmptyListResourcesResponse(): void
    {
        $listResources = new ListResources;
        $context = $this->getServerContext();
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/list', params: []);
        $result = $listResources->handle($jsonRpcRequest, $context);

        $this->assertMethodResult([
            'resources' => [],
        ], $result);
    }

    public function testReturnsAValidPopulatedListResourcesResponse(): void
    {
        $listResources = new ListResources;
        $resource = $this->makeResource();

        $context = $this->getServerContext([
            'resources' => [
                $resource,
            ],
        ]);
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/list', params: []);

        $this->assertMethodResult([
            'resources' => [
                [
                    'name' => $resource->name(),
                    'title' => $resource->title(),
                    'description' => $resource->description(),
                    'uri' => $resource->uri(),
                    'mimeType' => $resource->mimeType(),
                ],
            ],
        ], $listResources->handle($jsonRpcRequest, $context));
    }

    public function testReturnsEmptyListWhenTheSingleResourceIsNotEligibleForRegistration(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-resources',
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
            resources: [new class extends Resource {
                /**
                 * Return the resource content.
                 */
                public function handle(): string
                {
                    return 'foo';
                }

                /**
                 * Reject registration of the resource.
                 */
                public function shouldRegister(): bool
                {
                    return false;
                }
            }],
            prompts: [],
        );

        $listResources = new ListResources;

        $response = $listResources->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'resources' => [],
        ], $payload['result']);
    }

    public function testReturnsEmptyListWhenTheSingleResourceIsNotEligibleForRegistrationViaRequest(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list-resources',
            'params' => [
                'arguments' => ['register_resources' => false],
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
            resources: [new class extends Resource {
                /**
                 * Return the resource content.
                 */
                public function handle(): string
                {
                    return 'foo';
                }

                /**
                 * Determine resource registration from the request.
                 */
                public function shouldRegister(Request $request): bool
                {
                    return $request->get('register_resources', true);
                }
            }],
            prompts: [],
        );

        $listResources = new ListResources;

        CoroutineContext::set(Request::class, $request->toRequest());
        $response = $listResources->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertSame([
            'resources' => [],
        ], $payload['result']);
    }
}
