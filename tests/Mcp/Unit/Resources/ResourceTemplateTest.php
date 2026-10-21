<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Resources;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Support\UriTemplate;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Assert;

class ResourceTemplateTest extends TestCase
{
    public function testMatchesUrisAgainstATemplatePattern(): void
    {
        $resource = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://users/{userId}/files/{fileId}');
            }

            /**
             * Return the resource content.
             */
            public function handle(Request $request): Response
            {
                return Response::text('test');
            }
        };

        $matchingUri = 'file://users/123/files/document.txt';
        $nonMatchingUri = 'file://posts/123';

        $this->assertNotNull($resource->uriTemplate()->match($matchingUri));
        $this->assertNull($resource->uriTemplate()->match($nonMatchingUri));
    }

    public function testExtractsVariablesFromMatchingUri(): void
    {
        $resource = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://users/{userId}/files/{fileId}');
            }

            /**
             * Return the resource content.
             */
            public function handle(Request $request): Response
            {
                return Response::text('test');
            }
        };

        $uri = 'file://users/42/files/test.txt';
        $variables = $resource->uriTemplate()->match($uri);

        $this->assertSame([
            'userId' => '42',
            'fileId' => 'test.txt',
        ], $variables);
    }

    public function testHandlesTemplateResourceWithExtractedVariables(): void
    {
        $resource = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://users/{userId}/files/{fileId}');
            }

            /**
             * Return the requested file description.
             */
            public function handle(Request $request): Response
            {
                $userId = $request->get('userId');
                $fileId = $request->get('fileId');

                return Response::text("User: {$userId}, File: {$fileId}");
            }
        };

        $uri = 'file://users/100/files/data.json';
        $variables = $resource->uriTemplate()->match($uri);
        $request = new Request(['uri' => $uri, ...$variables]);

        $result = $resource->handle($request);
        $content = $result->content()->toResource($resource);

        $this->assertSame('User: 100, File: data.json', $content['text']);
    }

    public function testHandlesTemplateWithSingleVariable(): void
    {
        $resource = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://resource/{id}');
            }

            /**
             * Return the resource content.
             */
            public function handle(Request $request): Response
            {
                return Response::text('test');
            }
        };

        $this->assertNotNull($resource->uriTemplate()->match('file://resource/123'));
        $this->assertSame(['id' => 'abc'], $resource->uriTemplate()->match('file://resource/abc'));
    }

    public function testHandlesComplexUriTemplatesWithMultiplePathSegments(): void
    {
        $resource = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://organizations/{orgId}/projects/{projectId}/files/{fileId}');
            }

            /**
             * Return the resource content.
             */
            public function handle(Request $request): Response
            {
                return Response::text('test');
            }
        };

        $uri = 'file://organizations/acme/projects/website/files/index.html';
        $variables = $resource->uriTemplate()->match($uri);

        $this->assertSame([
            'orgId' => 'acme',
            'projectId' => 'website',
            'fileId' => 'index.html',
        ], $variables);
    }

    public function testDoesNotMatchUrisWithDifferentPathStructure(): void
    {
        $resource = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://users/{userId}/files/{fileId}');
            }

            /**
             * Return the resource content.
             */
            public function handle(Request $request): Response
            {
                return Response::text('test');
            }
        };

        $this->assertNull($resource->uriTemplate()->match('file://users/123'));
        $this->assertNull($resource->uriTemplate()->match('file://users/123/files/abc/extra'));
        $this->assertNull($resource->uriTemplate()->match('file://posts/123/files/abc'));
    }

    public function testEndToEndTemplateReadsUriExtractsVariablesAndReturnsResponse(): void
    {
        $template = new class extends Resource implements HasUriTemplate {
            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://api/{version}/users/{userId}/posts/{postId}');
            }

            /**
             * Validate the extracted arguments and return the post description.
             */
            public function handle(Request $request): Response
            {
                $version = $request->get('version');
                $userId = $request->get('userId');
                $postId = $request->get('postId');
                $uri = $request->get('uri');

                Assert::assertSame('v2', $version);
                Assert::assertSame('alice', $userId);
                Assert::assertSame('hello-world', $postId);
                Assert::assertSame('file://api/v2/users/alice/posts/hello-world', $uri);

                return Response::text("API {$version}: User {$userId} - Post {$postId}");
            }
        };

        $uri = 'file://api/v2/users/alice/posts/hello-world';

        $extractedVariables = $template->uriTemplate()->match($uri);

        $this->assertSame([
            'version' => 'v2',
            'userId' => 'alice',
            'postId' => 'hello-world',
        ], $extractedVariables);

        $request = new Request(['uri' => $uri, ...$extractedVariables]);

        $response = $template->handle($request);

        $content = $response->content()->toResource($template);

        $this->assertSame('API v2: User alice - Post hello-world', $content['text']);
        $this->assertSame('file://api/{version}/users/{userId}/posts/{postId}', $template->uri());
    }
}
