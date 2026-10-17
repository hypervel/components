<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Methods;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Mcp\Enums\ErrorCode;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Methods\ReadResource;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Support\UriTemplate;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\Fixtures\ResourceWithResultMetaResource;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class ReadResourceTest extends TestCase
{
    public function testReturnsAValidResourceResult(): void
    {
        $resource = $this->makeResource('resource-content');
        $readResource = new ReadResource;
        $context = $this->getServerContext([
            'resources' => [
                $resource,
            ],
        ]);
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/read', params: ['uri' => $resource->uri()]);
        $resourceResult = $readResource->handle($jsonRpcRequest, $context);

        $this->assertPartialMethodResult([
            'contents' => [
                [
                    'text' => 'resource-content',
                ],
            ],
        ], $resourceResult);
    }

    public function testReturnsAValidResourceResultForBlobResources(): void
    {
        $resource = $this->makeBinaryResource(__DIR__ . '/../../Fixtures/binary.png');
        $readResource = new ReadResource;
        $context = $this->getServerContext([
            'resources' => [
                $resource,
            ],
        ]);
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/read', params: ['uri' => $resource->uri()]);
        $resourceResult = $readResource->handle($jsonRpcRequest, $context);

        $this->assertPartialMethodResult([
            'contents' => [
                [
                    'blob' => base64_encode(file_get_contents(__DIR__ . '/../../Fixtures/binary.png')),
                ],
            ],
        ], $resourceResult);
    }

    public function testThrowsErrorWhenUriIsMissing(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Missing [uri] parameter.');
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS->value);

        $readResource = new ReadResource;
        $context = $this->getServerContext();

        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: [] // intentionally omitting 'uri'
        );

        try {
            $readResource->handle($jsonRpcRequest, $context);
        } catch (JsonRpcException $exception) {
            $this->assertNull($exception->data());

            throw $exception;
        }
    }

    public function testThrowsExceptionWhenResourceIsNotFound(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS->value);

        $readResource = new ReadResource;
        $context = $this->getServerContext();

        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => 'file://resources/non-existent']
        );

        try {
            $readResource->handle($jsonRpcRequest, $context);
        } catch (JsonRpcException $exception) {
            $this->assertSame(['uri' => 'file://resources/non-existent'], $exception->data());

            throw $exception;
        }
    }

    public function testThrowsInternalErrorWithExceptionMessageWhenModelNotFoundExceptionIsThrownWithAppDebugTrue(): void
    {
        config(['app.debug' => true]);

        $resource = new class extends Resource {
            protected string $uri = 'file://resources/user-resource';

            protected string $mimeType = 'text/plain';

            /**
             * Fail to load the requested model.
             */
            public function handle(): string
            {
                throw new ModelNotFoundException('No query results for model [App\Models\User] 42.');
            }
        };

        $readResource = new ReadResource;
        $context = $this->getServerContext(['resources' => [$resource]]);
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/read', params: ['uri' => 'file://resources/user-resource']);

        try {
            $readResource->handle($jsonRpcRequest, $context);
            $this->fail('Expected JsonRpcException to be thrown');
        } catch (JsonRpcException $jsonRpcException) {
            $this->assertSame(-32603, $jsonRpcException->getCode());
            $this->assertSame('No query results for model [App\Models\User] 42.', $jsonRpcException->getMessage());
        }
    }

    public function testThrowsInternalErrorWithGenericMessageWhenModelNotFoundExceptionIsThrownWithAppDebugFalse(): void
    {
        config(['app.debug' => false]);

        $resource = new class extends Resource {
            protected string $uri = 'file://resources/user-resource';

            protected string $mimeType = 'text/plain';

            /**
             * Fail to load the requested model.
             */
            public function handle(): string
            {
                throw new ModelNotFoundException('No query results for model [App\Models\User] 42.');
            }
        };

        $readResource = new ReadResource;
        $context = $this->getServerContext(['resources' => [$resource]]);
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/read', params: ['uri' => 'file://resources/user-resource']);

        try {
            $readResource->handle($jsonRpcRequest, $context);
            $this->fail('Expected JsonRpcException to be thrown');
        } catch (JsonRpcException $jsonRpcException) {
            $this->assertSame(-32603, $jsonRpcException->getCode());
            $this->assertSame('An internal server error occurred.', $jsonRpcException->getMessage());
        }
    }

    public function testThrowsInternalErrorWhenResourceHandlerThrowsAGenericException(): void
    {
        config(['app.debug' => true]);

        $resource = new class extends Resource {
            protected string $uri = 'file://resources/failing-resource';

            protected string $mimeType = 'text/plain';

            /**
             * Fail the resource invocation.
             */
            public function handle(): string
            {
                throw new RuntimeException('Something went wrong internally.');
            }
        };

        $readResource = new ReadResource;
        $context = $this->getServerContext(['resources' => [$resource]]);
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/read', params: ['uri' => 'file://resources/failing-resource']);

        try {
            $readResource->handle($jsonRpcRequest, $context);
            $this->fail('Expected JsonRpcException to be thrown');
        } catch (JsonRpcException $jsonRpcException) {
            $this->assertSame(-32603, $jsonRpcException->getCode());
            $this->assertStringContainsString('Something went wrong internally.', $jsonRpcException->getMessage());
        }
    }

    public function testIncludesExceptionMessageInResourceInternalErrorWhenAppDebugIsTrue(): void
    {
        config(['app.debug' => true]);

        $resource = new class extends Resource {
            protected string $uri = 'file://resources/debug-resource';

            protected string $mimeType = 'text/plain';

            /**
             * Fail the resource invocation.
             */
            public function handle(): string
            {
                throw new RuntimeException('Debug this.');
            }
        };

        $readResource = new ReadResource;
        $context = $this->getServerContext(['resources' => [$resource]]);
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/read', params: ['uri' => 'file://resources/debug-resource']);

        try {
            $readResource->handle($jsonRpcRequest, $context);
            $this->fail('Expected JsonRpcException to be thrown');
        } catch (JsonRpcException $jsonRpcException) {
            $this->assertSame(-32603, $jsonRpcException->getCode());
            $this->assertSame('Debug this.', $jsonRpcException->getMessage());
        }
    }

    public function testReturnsPlainMessageInResourceInternalErrorWhenAppDebugIsFalse(): void
    {
        config(['app.debug' => false]);

        $resource = new class extends Resource {
            protected string $uri = 'file://resources/plain-resource';

            protected string $mimeType = 'text/plain';

            /**
             * Fail the resource invocation.
             */
            public function handle(): string
            {
                throw new RuntimeException('Plain error message.');
            }
        };

        $readResource = new ReadResource;
        $context = $this->getServerContext(['resources' => [$resource]]);
        $jsonRpcRequest = new JsonRpcRequest(id: 1, method: 'resources/read', params: ['uri' => 'file://resources/plain-resource']);

        try {
            $readResource->handle($jsonRpcRequest, $context);
            $this->fail('Expected JsonRpcException to be thrown');
        } catch (JsonRpcException $jsonRpcException) {
            $this->assertSame(-32603, $jsonRpcException->getCode());
            $this->assertSame('An internal server error occurred.', $jsonRpcException->getMessage());
        }
    }

    public function testReadsResourceTemplateByMatchingAUriPattern(): void
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
             * Return content for the matched resource.
             */
            public function handle(Request $request): Response
            {
                return Response::text('Template matched!');
            }
        };

        $context = $this->getServerContext([
            'resources' => [$template],
        ]);

        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => 'file://users/123']
        );

        $readResource = new ReadResource;
        $result = $readResource->handle($jsonRpcRequest, $context);

        $this->assertPartialMethodResult([
            'contents' => [
                ['text' => 'Template matched!'],
            ],
        ], $result);
    }

    public function testReturnsTheActualRequestedUriInResponseNotTheTemplatePattern(): void
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
             * Return content for the matched resource.
             */
            public function handle(Request $request): Response
            {
                return Response::text('User data');
            }
        };

        $context = $this->getServerContext([
            'resources' => [$template],
        ]);

        $requestedUri = 'file://users/42';
        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => $requestedUri]
        );

        $readResource = new ReadResource;
        $result = $readResource->handle($jsonRpcRequest, $context);
        $payload = $result->toArray();

        $this->assertSame($requestedUri, $payload['result']['contents'][0]['uri']);
        $this->assertNotSame('file://users/{userId}', $payload['result']['contents'][0]['uri']);
    }

    #[DataProvider('templateVariables')]
    public function testExtractsVariablesFromUriTemplateAndPassesToHandler(string $templatePattern, string $uri, array $expected): void
    {
        $resource = new class($templatePattern) extends Resource implements HasUriTemplate {
            /**
             * Set the resource URI pattern.
             */
            public function __construct(private string $pattern)
            {
            }

            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate($this->pattern);
            }

            /**
             * Return the resolved request arguments.
             */
            public function handle(Request $request): Response
            {
                return Response::json($request->all());
            }
        };

        $context = $this->getServerContext([
            'resources' => [$resource],
        ]);

        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => $uri]
        );

        $readResource = new ReadResource;
        $result = $readResource->handle($jsonRpcRequest, $context);
        $payload = $result->toArray();

        $responseData = json_decode((string) $payload['result']['contents'][0]['text'], true);

        $this->assertSame($expected, $responseData);
    }

    /**
     * Return resource template substitutions.
     */
    public static function templateVariables(): array
    {
        return [
            'single variable' => [
                'templatePattern' => 'file://users/{userId}',
                'uri' => 'file://users/42',
                'expected' => ['userId' => '42'],
            ],
            'multiple variables' => [
                'templatePattern' => 'file://users/{userId}/files/{fileId}',
                'uri' => 'file://users/100/files/document.pdf',
                'expected' => ['userId' => '100', 'fileId' => 'document.pdf'],
            ],
        ];
    }

    public function testPreservesMetaFromTheOriginalRequestForTemplateResources(): void
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
             * Return request metadata and arguments.
             */
            public function handle(Request $request): Response
            {
                return Response::json([
                    'meta' => $request->meta(),
                    'arguments' => $request->all(),
                ]);
            }
        };

        $context = $this->getServerContext([
            'resources' => [$template],
        ]);

        $meta = ['progressToken' => 'abc123'];
        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: [
                'uri' => 'file://users/42',
                'arguments' => ['format' => 'json'],
                '_meta' => $meta,
            ],
        );

        CoroutineContext::set(Request::class, $jsonRpcRequest->toRequest());
        $readResource = new ReadResource;
        $result = $readResource->handle($jsonRpcRequest, $context);
        $payload = $result->toArray();

        $responseData = json_decode((string) $payload['result']['contents'][0]['text'], true);

        $this->assertSame($meta, $responseData['meta']);
        $this->assertSame('42', $responseData['arguments']['userId']);
        $this->assertSame('json', $responseData['arguments']['format']);
    }

    public function testTemplateHandlerReceivesVariablesViaRequestGetMethod(): void
    {
        $template = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://posts/{postId}/comments/{commentId}');
            }

            /**
             * Return the matched template variables.
             */
            public function handle(Request $request): Response
            {
                return Response::json([
                    'postId' => $request->get('postId'),
                    'commentId' => $request->get('commentId'),
                ]);
            }
        };

        $context = $this->getServerContext([
            'resources' => [$template],
        ]);

        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => 'file://posts/42/comments/7']
        );

        $readResource = new ReadResource;
        $result = $readResource->handle($jsonRpcRequest, $context);
        $payload = $result->toArray();

        $responseData = json_decode((string) $payload['result']['contents'][0]['text'], true);

        $this->assertSame('42', $responseData['postId']);
        $this->assertSame('7', $responseData['commentId']);
    }

    public function testTriesStaticResourcesBeforeTemplateMatching(): void
    {
        $staticResource = $this->makeResource('Static resource content');

        $template = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://resources/{resourceId}');
            }

            /**
             * Return content for the matched resource.
             */
            public function handle(Request $request): Response
            {
                return Response::text('Template matched!');
            }
        };

        $context = $this->getServerContext([
            'resources' => [$staticResource, $template],
        ]);

        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => $staticResource->uri()]
        );

        $readResource = new ReadResource;
        $result = $readResource->handle($jsonRpcRequest, $context);

        $this->assertPartialMethodResult([
            'contents' => [
                ['text' => 'Static resource content'],
            ],
        ], $result);
    }

    public function testReturnsTheFirstMatchingTemplateWhenMultipleTemplatesExist(): void
    {
        $template1 = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://users/{userId}');
            }

            /**
             * Return the first template's content.
             */
            public function handle(Request $request): Response
            {
                return Response::text('First template');
            }
        };

        $template2 = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://users/{id}');
            }

            /**
             * Return the second template's content.
             */
            public function handle(Request $request): Response
            {
                return Response::text('Second template');
            }
        };

        $context = $this->getServerContext([
            'resources' => [$template1, $template2],
        ]);

        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => 'file://users/42']
        );

        $readResource = new ReadResource;
        $result = $readResource->handle($jsonRpcRequest, $context);

        $this->assertPartialMethodResult([
            'contents' => [
                ['text' => 'First template'],
            ],
        ], $result);
    }

    public function testThrowsExceptionWhenUriDoesNotMatchAnyTemplatePattern(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Resource [file://posts/123] not found.');

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

        $context = $this->getServerContext([
            'resources' => [$template],
        ]);

        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => 'file://posts/123']
        );

        $readResource = new ReadResource;
        $readResource->handle($jsonRpcRequest, $context);
    }

    public function testReturnsAResourceResultWithResultLevelMetaWhenUsingResponseFactory(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => [
                'uri' => 'file://resources/with-result-meta.txt',
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
            resources: [ResourceWithResultMetaResource::class],
            prompts: [],
        );

        $method = new ReadResource;

        $response = $method->handle($request, $context);

        $this->assertInstanceOf(JsonRpcResponse::class, $response);

        $payload = $response->toArray();

        $this->assertSame(1, $payload['id']);
        $this->assertPartialArray([
            'result' => [
                '_meta' => [
                    'last_modified' => '2025-01-01',
                    'version' => '1.0',
                ],
                'contents' => [
                    [
                        'text' => 'Resource content with result meta',
                        'uri' => 'file://resources/with-result-meta.txt',
                        'mimeType' => 'text/plain',
                    ],
                ],
            ],
        ], $payload);
    }

    public function testDoesNotLeakVariablesBetweenConsecutiveTemplateResourceRequests(): void
    {
        $template = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://users/{userId}/posts/{postId}');
            }

            /**
             * Return the request arguments.
             */
            public function handle(Request $request): Response
            {
                return Response::json($request->all());
            }
        };

        $context = $this->getServerContext([
            'resources' => [$template],
        ]);

        $readResource = new ReadResource;

        $firstJsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => 'file://users/100/posts/42']
        );
        $firstResult = $readResource->handle($firstJsonRpcRequest, $context);
        $firstPayload = $firstResult->toArray();
        $firstRequestVars = json_decode((string) $firstPayload['result']['contents'][0]['text'], true);

        $secondJsonRpcRequest = new JsonRpcRequest(
            id: 2,
            method: 'resources/read',
            params: ['uri' => 'file://users/200/posts/99']
        );
        $secondResult = $readResource->handle($secondJsonRpcRequest, $context);
        $secondPayload = $secondResult->toArray();
        $secondRequestVars = json_decode((string) $secondPayload['result']['contents'][0]['text'], true);

        $this->assertSame([
            'userId' => '100',
            'postId' => '42',
        ], $firstRequestVars);
        $this->assertSame([
            'userId' => '200',
            'postId' => '99',
        ], $secondRequestVars);
        $this->assertNotSame('100', $secondRequestVars['userId']);
        $this->assertNotSame('42', $secondRequestVars['postId']);
    }

    public function testSetsUriOnRequestWhenReadingResourceTemplates(): void
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
             * Return the request URI.
             */
            public function handle(Request $request): Response
            {
                return Response::json(['uri' => $request->uri()]);
            }
        };

        $context = $this->getServerContext([
            'resources' => [$template],
        ]);

        $requestedUri = 'file://users/42';
        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => $requestedUri]
        );

        $readResource = new ReadResource;
        $result = $readResource->handle($jsonRpcRequest, $context);
        $payload = $result->toArray();

        $responseData = json_decode((string) $payload['result']['contents'][0]['text'], true);

        $this->assertSame($requestedUri, $responseData['uri']);
    }

    public function testProvidesBothUriAndExtractedVariablesInRequestForTemplates(): void
    {
        $template = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://users/{userId}/files/{fileId}');
            }

            /**
             * Return the request URI and template variables.
             */
            public function handle(Request $request): Response
            {
                return Response::json([
                    'uri' => $request->uri(),
                    'userId' => $request->get('userId'),
                    'fileId' => $request->get('fileId'),
                ]);
            }
        };

        $context = $this->getServerContext([
            'resources' => [$template],
        ]);

        $requestedUri = 'file://users/123/files/document.pdf';
        $jsonRpcRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => $requestedUri]
        );

        $readResource = new ReadResource;
        $result = $readResource->handle($jsonRpcRequest, $context);
        $payload = $result->toArray();

        $responseData = json_decode((string) $payload['result']['contents'][0]['text'], true);

        $this->assertSame([
            'uri' => 'file://users/123/files/document.pdf',
            'userId' => '123',
            'fileId' => 'document.pdf',
        ], $responseData);
    }

    #[DataProvider('consecutiveResourceUris')]
    public function testUriIsCorrectlySetAndIsolatedForConsecutiveRequests(string $resourceType, string $firstUri, string $secondUri): void
    {
        if ($resourceType === 'template') {
            $resource = new class extends Resource implements HasUriTemplate {
                /**
                 * Return the resource URI template.
                 */
                public function uriTemplate(): UriTemplate
                {
                    return new UriTemplate('file://users/{userId}');
                }

                /**
                 * Return the request URI.
                 */
                public function handle(Request $request): Response
                {
                    return Response::json(['uri' => $request->uri()]);
                }
            };
        } else {
            $resource = new class extends Resource {
                protected string $uri = 'file://static/resource';

                protected string $mimeType = 'text/plain';

                /**
                 * Return the request URI.
                 */
                public function handle(Request $request): Response
                {
                    return Response::json(['uri' => $request->uri()]);
                }
            };
        }

        $context = $this->getServerContext([
            'resources' => [$resource],
        ]);

        $readResource = new ReadResource;

        $firstRequest = new JsonRpcRequest(
            id: 1,
            method: 'resources/read',
            params: ['uri' => $firstUri]
        );
        $firstResult = $readResource->handle($firstRequest, $context);
        $firstPayload = $firstResult->toArray();
        $firstResponseData = json_decode((string) $firstPayload['result']['contents'][0]['text'], true);

        $secondRequest = new JsonRpcRequest(
            id: 2,
            method: 'resources/read',
            params: ['uri' => $secondUri]
        );
        $secondResult = $readResource->handle($secondRequest, $context);
        $secondPayload = $secondResult->toArray();
        $secondResponseData = json_decode((string) $secondPayload['result']['contents'][0]['text'], true);

        $this->assertSame($firstUri, $firstResponseData['uri']);
        $this->assertSame($secondUri, $secondResponseData['uri']);
    }

    /**
     * Return consecutive resource requests.
     */
    public static function consecutiveResourceUris(): array
    {
        return [
            'template resources' => [
                'resourceType' => 'template',
                'firstUri' => 'file://users/100',
                'secondUri' => 'file://users/200',
            ],
            'static resources' => [
                'resourceType' => 'static',
                'firstUri' => 'file://static/resource',
                'secondUri' => 'file://static/resource',
            ],
        ];
    }

    #[DataProvider('invalidUris')]
    public function testRejectsNonStringUris(mixed $uri): void
    {
        $request = new JsonRpcRequest(42, 'resources/read', ['uri' => $uri]);

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(-32602);
        $this->expectExceptionMessage('The [uri] parameter must be a string.');

        (new ReadResource)->handle($request, $this->getServerContext());
    }

    /**
     * Return malformed resource URIs.
     */
    public static function invalidUris(): array
    {
        return [[1], [[]], [false]];
    }
}
