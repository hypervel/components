<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Methods;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Methods\ListResourceTemplates;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Support\UriTemplate;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Tests\Mcp\TestCase;

class ListResourceTemplatesTest extends TestCase
{
    public function testListsOnlyResourceTemplates(): void
    {
        $staticResource = new class extends Resource {
            protected string $uri = 'file://logs/app.log';

            /**
             * Return the log content.
             */
            public function handle(): Response
            {
                return Response::text('log');
            }
        };

        $templateResource = new class extends Resource implements HasUriTemplate {
            protected string $mimeType = 'text/plain';

            protected string $description = 'User files';

            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://users/{userId}/files/{fileId}');
            }

            /**
             * Return the file content.
             */
            public function handle(Request $request): Response
            {
                return Response::text('file content');
            }
        };

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 15,
            tools: [],
            resources: [$staticResource, $templateResource],
            prompts: [],
        );

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/templates/list',
            'params' => [],
        ]);

        $handler = new ListResourceTemplates;
        $response = $handler->handle($request, $context);
        $payload = $response->toArray();

        $this->assertArrayHasKey('result', $payload);
        $this->assertArrayHasKey('resourceTemplates', $payload['result']);
        $this->assertCount(1, $payload['result']['resourceTemplates']);
        $this->assertArrayHasKey('uriTemplate', $payload['result']['resourceTemplates'][0]);
        $this->assertSame('file://users/{userId}/files/{fileId}', $payload['result']['resourceTemplates'][0]['uriTemplate']);
    }

    public function testReturnsAnEmptyListWhenNoTemplatesExist(): void
    {
        $staticResource = new class extends Resource {
            protected string $uri = 'file://logs/app.log';

            /**
             * Return the log content.
             */
            public function handle(): Response
            {
                return Response::text('log');
            }
        };

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 15,
            tools: [],
            resources: [$staticResource],
            prompts: [],
        );

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/templates/list',
            'params' => [],
        ]);

        $handler = new ListResourceTemplates;
        $response = $handler->handle($request, $context);
        $payload = $response->toArray();

        $this->assertIsArray($payload['result']['resourceTemplates']);
        $this->assertEmpty($payload['result']['resourceTemplates']);
    }

    public function testIncludesTemplateMetadataInTheListing(): void
    {
        $templateResource = new class extends Resource implements HasUriTemplate {
            protected string $name = 'user-file';

            protected string $title = 'User File Resource';

            protected string $description = 'Access user files by ID';

            protected string $mimeType = 'application/json';

            /**
             * Return the resource URI template.
             */
            public function uriTemplate(): UriTemplate
            {
                return new UriTemplate('file://users/{id}/data');
            }

            /**
             * Return the file content.
             */
            public function handle(Request $request): Response
            {
                return Response::text('data');
            }
        };

        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 15,
            tools: [],
            resources: [$templateResource],
            prompts: [],
        );

        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/templates/list',
            'params' => [],
        ]);

        $handler = new ListResourceTemplates;
        $response = $handler->handle($request, $context);
        $payload = $response->toArray();

        $template = $payload['result']['resourceTemplates'][0];

        $this->assertSame('user-file', $template['name']);
        $this->assertSame('User File Resource', $template['title']);
        $this->assertSame('Access user files by ID', $template['description']);
        $this->assertSame('file://users/{id}/data', $template['uriTemplate']);
        $this->assertSame('application/json', $template['mimeType']);
    }
}
