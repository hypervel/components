<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\McpServiceProvider;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Testbench\TestCase as TestbenchTestCase;
use Hypervel\Tests\Mcp\Fixtures\Workbench\Providers\WorkbenchServiceProvider;

require_once __DIR__ . '/Fixtures/helpers.php';

abstract class TestCase extends TestbenchTestCase
{
    /**
     * Remove generated MCP files from the worker's shared runtime skeleton.
     */
    protected function tearDown(): void
    {
        try {
            $files = new Filesystem;
            $files->deleteDirectory(app_path('Mcp'));
            $files->deleteDirectory(resource_path('views/mcp'));
            $files->delete([
                base_path('stubs/mcp-app-resource.stub'),
                base_path('stubs/mcp-app-resource.view.stub'),
                base_path('stubs/mcp-prompt.stub'),
                base_path('stubs/mcp-resource.stub'),
                base_path('stubs/mcp-server.stub'),
                base_path('stubs/mcp-tool.stub'),
            ]);
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Register the MCP provider and the test server registrations.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [McpServiceProvider::class, WorkbenchServiceProvider::class];
    }

    /**
     * Configure the package test environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('app.debug', true);
        $app->make('config')->set('cache.default', 'array');
    }

    /**
     * Create a server context with the supplied overrides.
     */
    protected function getServerContext(array $properties = []): ServerContext
    {
        $properties = array_merge([
            'supportedProtocolVersions' => [],
            'serverCapabilities' => [],
            'implementation' => new Implementation('test-server', '1.0.0'),
            'instructions' => 'test-instructions',
            'maxPaginationLength' => 3,
            'defaultPaginationLength' => 3,
            'tools' => [],
            'resources' => [],
            'prompts' => [],
        ], $properties);

        return new ServerContext(...$properties);
    }

    /**
     * Assert the complete method result.
     */
    protected function assertMethodResult(array $expected, JsonRpcResponse $response): void
    {
        $result = data_get($response->toArray(), 'result');

        $this->assertEquals($expected, $result);
    }

    /**
     * Assert selected method result values recursively.
     */
    protected function assertPartialMethodResult(array $expected, JsonRpcResponse $response): void
    {
        $result = data_get($response->toArray(), 'result');

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $result);
            if (is_array($value)) {
                $this->assertPartialArray($value, $result[$key]);
            } else {
                $this->assertEquals($value, $result[$key]);
            }
        }
    }

    /**
     * Assert selected array values recursively.
     */
    protected function assertPartialArray(array $expected, array $actual): void
    {
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual);
            if (is_array($value)) {
                $this->assertPartialArray($value, $actual[$key]);
            } else {
                $this->assertEquals($value, $actual[$key]);
            }
        }
    }

    /**
     * Create a resource with the supplied content and metadata.
     */
    protected function makeResource(
        string $content = 'resource-content',
        string $description = 'A test resource',
        array $overrides = [],
    ): Resource {
        return new class($content, $description, $overrides) extends Resource {
            /**
             * Create the test resource.
             */
            public function __construct(
                private string $contentValue,
                private string $resourceDescription,
                private array $overrides,
            ) {
            }

            /**
             * Return the resource description.
             */
            public function description(): string
            {
                return $this->resourceDescription;
            }

            /**
             * Return the resource content.
             */
            public function handle(): string
            {
                return $this->contentValue;
            }

            /**
             * Return the resource URI.
             */
            public function uri(): string
            {
                return $this->overrides['uri'] ?? parent::uri();
            }

            /**
             * Return the resource MIME type.
             */
            public function mimeType(): string
            {
                return $this->overrides['mimeType'] ?? parent::mimeType();
            }
        };
    }

    /**
     * Create a resource from a binary fixture.
     */
    protected function makeBinaryResource(
        string $filePath,
        string $description = 'A binary resource',
        array $overrides = [],
    ): Resource {
        $content = file_get_contents($filePath);
        $overrides['mimeType'] ??= mime_content_type($filePath) ?: 'application/octet-stream';

        return $this->makeResource($content, $description, $overrides);
    }
}
