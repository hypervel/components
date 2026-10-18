<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Tools;

use Generator;
use Hypervel\Ai\ObjectSchema;
use Hypervel\Ai\Tools\McpServerTool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Mcp\Request as McpRequest;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\McpServiceProvider;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeArrayMcpServerTool;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeErroringMcpServerTool;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeMcpServerTool;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeStreamingMcpServerTool;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeStructuredMcpServerTool;
use RuntimeException;
use stdClass;

use function Hypervel\Coroutine\parallel;

class McpServerToolTest extends TestCase
{
    /**
     * Register the MCP request hydration callbacks.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [McpServiceProvider::class];
    }

    public function testItDetectsMcpServerToolPrimitives(): void
    {
        $this->assertTrue(McpServerTool::supports(new FakeMcpServerTool));
        $this->assertFalse(McpServerTool::supports(new stdClass));
    }

    public function testItExposesTheToolNameDescriptionAndSchema(): void
    {
        $tool = new McpServerTool(new FakeMcpServerTool);

        $this->assertSame('fake-mcp-server-tool', $tool->name());
        $this->assertSame('Fetches the current weather for a city.', $tool->description());

        $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

        $this->assertEquals([
            'required' => ['city'],
            'properties' => [
                'city' => [
                    'type' => 'string',
                    'description' => 'The city to get the weather for.',
                ],
                'units' => [
                    'type' => 'string',
                    'enum' => ['celsius', 'fahrenheit'],
                    'default' => 'celsius',
                ],
            ],
        ], array_intersect_key($schema, array_flip(['required', 'properties'])));
    }

    public function testItInvokesTheUnderlyingToolAndReturnsTextContent(): void
    {
        $serverTool = new FakeMcpServerTool;
        $tool = new McpServerTool($serverTool);

        $result = $tool->handle(new Request(['city' => 'Paris']));

        $this->assertSame('Sunny in Paris.', $result);
        $this->assertSame([['city' => 'Paris']], $serverTool->invocations);
    }

    public function testItSerializesStructuredToolResponsesAsJson(): void
    {
        $tool = new McpServerTool(new FakeStructuredMcpServerTool);

        $result = $tool->handle(new Request(['city' => 'Paris']));

        $this->assertSame([
            'temperature' => 72,
            'conditions' => 'Sunny',
        ], array_intersect_key(json_decode($result, true, flags: JSON_THROW_ON_ERROR), array_flip(['temperature', 'conditions'])));
    }

    public function testItDoesNotEscapeSlashesInStructuredContentJson(): void
    {
        $tool = new McpServerTool(new FakeStructuredMcpServerTool);

        $this->assertStringContainsString('"url":"https://example.com/report"', $tool->handle(new Request(['city' => 'Paris'])));
    }

    public function testItReplacesInvalidUtf8InStructuredContent(): void
    {
        $tool = new McpServerTool(new class extends FakeStructuredMcpServerTool {
            /**
             * Return structured content containing invalid text bytes.
             */
            public function handle(McpRequest $request): ResponseFactory
            {
                return Response::make(Response::text('Weather'))->withStructuredContent(['city' => "Par\xB1s"]);
            }
        });

        $this->assertSame('{"city":"Par�s"}', $tool->handle(new Request));
    }

    public function testItSurfacesToolErrorsWithTheStandardPrefix(): void
    {
        $tool = new McpServerTool(new FakeErroringMcpServerTool);

        $this->assertSame('MCP tool error: Something went wrong.', $tool->handle(new Request));
    }

    public function testItReturnsOnlyTheFinalYieldedResponseAndIgnoresNotificationsAndIntermediateUpdates(): void
    {
        $tool = new McpServerTool(new FakeStreamingMcpServerTool);

        $this->assertSame('Third.', $tool->handle(new Request));
    }

    public function testItReturnsOnlyTheFinalItemFromAnArrayResponse(): void
    {
        $tool = new McpServerTool(new FakeArrayMcpServerTool);

        $this->assertSame('Third.', $tool->handle(new Request));
    }

    public function testLazilyYieldedResponsesStillResolveTheScopedMcpRequest(): void
    {
        $tool = new McpServerTool(new class extends Tool {
            /**
             * Handle the tool request.
             */
            public function handle(McpRequest $request): Generator
            {
                yield Response::text('First. ');
                yield Response::text(Container::getInstance()->make(McpRequest::class)->get('city'));
            }

            /**
             * Get the tool schema.
             */
            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        });

        $this->assertSame('Paris', $tool->handle(new Request(['city' => 'Paris'])));
    }

    public function testTheMcpRequestContextIsClearedAfterTheCall(): void
    {
        $tool = new McpServerTool(new FakeMcpServerTool);

        $tool->handle(new Request(['city' => 'Paris']));

        $this->assertFalse(CoroutineContext::has(McpRequest::class));
    }

    public function testOverlappingCallsKeepTheirRequestsThroughLazyResponses(): void
    {
        $tool = new McpServerTool(new class extends FakeStreamingMcpServerTool {
            /**
             * Handle the tool request.
             */
            public function handle(McpRequest $request): Generator
            {
                yield Response::text('Starting.');
                usleep(5000);
                yield Response::text(Container::getInstance()->make(McpRequest::class)->get('city'));
            }
        });

        $this->assertSame(['Paris', 'London'], parallel([
            fn (): string => $tool->handle(new Request(['city' => 'Paris'])),
            fn (): string => $tool->handle(new Request(['city' => 'London'])),
        ]));
    }

    public function testNestedFailureRestoresTheOuterRequest(): void
    {
        $outer = new McpRequest(['city' => 'Paris']);
        CoroutineContext::set(McpRequest::class, $outer);
        $failure = new RuntimeException('Tool failed.');
        $tool = new McpServerTool(new class($failure) extends FakeStreamingMcpServerTool {
            /**
             * Create a failing tool.
             */
            public function __construct(private RuntimeException $failure)
            {
            }

            /**
             * Handle the tool request.
             */
            public function handle(McpRequest $request): Generator
            {
                yield Response::text($request->get('city'));
                throw $this->failure;
            }
        });

        try {
            $tool->handle(new Request(['city' => 'London']));
            $this->fail('The tool failure must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame('Paris', $this->app->make(McpRequest::class)->get('city'));
    }
}
