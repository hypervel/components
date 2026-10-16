<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Methods;

use Hypervel\Context\CoroutineContext;
use Hypervel\Mcp\Enums\Role;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\Annotations\Audience;
use Hypervel\Mcp\Server\Annotations\LastModified;
use Hypervel\Mcp\Server\Annotations\Priority;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Methods\ListResources;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Support\UriTemplate;
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
                 * Exclude the resource from registration.
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
            'method' => 'resources/list',
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
                 * Determine eligibility from the invocation request.
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

    public function testIncludesAnnotationsWhenAResourceHasAnnotations(): void
    {
        $listResources = new ListResources;

        $resource = new #[Audience([Role::User, Role::Assistant])]
        #[Priority(0.8)]
        #[LastModified('2025-01-12T15:00:58Z')]
        class extends Resource {
            /**
             * Return the resource content.
             */
            public function handle(): string
            {
                return 'test content';
            }
        };

        $context = $this->getServerContext([
            'resources' => [$resource],
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
                    'annotations' => [
                        'audience' => ['user', 'assistant'],
                        'priority' => 0.8,
                        'lastModified' => '2025-01-12T15:00:58Z',
                    ],
                ],
            ],
        ], $listResources->handle($jsonRpcRequest, $context));
    }

    public function testExcludesAnAnnotationKeyWhenAResourceHasNoAnnotations(): void
    {
        $listResources = new ListResources;

        $resource = new class extends Resource {
            /**
             * Return the resource content.
             */
            public function handle(): string
            {
                return 'test content';
            }
        };

        $context = $this->getServerContext([
            'resources' => [$resource],
        ]);
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/list', params: []);

        $response = $listResources->handle($jsonRpcRequest, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();

        $this->assertCount(1, $payload['result']['resources']);
        $this->assertArrayNotHasKey('annotations', $payload['result']['resources'][0]);
    }

    public function testHandlesMixedResourcesWithAndWithoutAnnotations(): void
    {
        $listResources = new ListResources;

        $annotatedResource = new #[Audience(Role::User)]
        #[Priority(0.5)]
        class extends Resource {
            /**
             * Return the resource content.
             */
            public function handle(): string
            {
                return 'annotated content';
            }
        };

        $plainResource = new class extends Resource {
            /**
             * Return the resource content.
             */
            public function handle(): string
            {
                return 'plain content';
            }
        };

        $context = $this->getServerContext([
            'resources' => [$annotatedResource, $plainResource],
        ]);
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/list', params: []);

        $response = $listResources->handle($jsonRpcRequest, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);
        $payload = $response->toArray();

        $this->assertCount(2, $payload['result']['resources']);
        $this->assertArrayHasKey('annotations', $payload['result']['resources'][0]);
        $this->assertSame([
            'audience' => ['user'],
            'priority' => 0.5,
        ], $payload['result']['resources'][0]['annotations']);
        $this->assertArrayNotHasKey('annotations', $payload['result']['resources'][1]);
    }

    public function testExcludesResourceTemplatesFromList(): void
    {
        $template = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://users/{userId}');
            }

            /**
             * Return the resource content.
             */
            public function handle(Request $request): Response
            {
                return Response::text('test');
            }
        };

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 5,
            tools: [],
            resources: [$template],
            prompts: [],
        );

        $request = new JsonRpcRequest(id: 1, method: 'resources/list', params: []);
        $listResources = new ListResources;
        $response = $listResources->handle($request, $context);

        $payload = $response->toArray();

        $this->assertSame([
            'resources' => [],
        ], $payload['result']);
    }

    public function testReturnsOnlyStaticResourcesWhenBothTemplatesAndStaticResourcesExist(): void
    {
        $staticResource = $this->makeResource();

        $template = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://users/{userId}');
            }

            /**
             * Return the resource content.
             */
            public function handle(Request $request): Response
            {
                return Response::text('test');
            }
        };

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 5,
            tools: [],
            resources: [$staticResource, $template],
            prompts: [],
        );

        $request = new JsonRpcRequest(id: 1, method: 'resources/list', params: []);
        $listResources = new ListResources;
        $response = $listResources->handle($request, $context);

        $payload = $response->toArray();

        $this->assertCount(1, $payload['result']['resources']);
        $this->assertSame($staticResource->name(), $payload['result']['resources'][0]['name']);
        $this->assertSame($staticResource->uri(), $payload['result']['resources'][0]['uri']);
    }
}
