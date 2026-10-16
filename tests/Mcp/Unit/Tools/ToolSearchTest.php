<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Tools;

use Generator;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Contracts\Transport;
use Hypervel\Mcp\Server\Methods\CallTool;
use Hypervel\Mcp\Server\RequestContext;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Server\Tools\Annotations\IsDestructive;
use Hypervel\Mcp\Server\Tools\ExecuteTools;
use Hypervel\Mcp\Server\Tools\SearchTools;
use Hypervel\Mcp\Server\Tools\ToolSearch;
use Hypervel\Mcp\Server\Transport\FakeTransporter;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\Fixtures\ArrayTransport;
use Hypervel\Tests\Mcp\Fixtures\SayHiTool;
use Hypervel\Tests\Mcp\Fixtures\StreamingTool;
use Hypervel\Tests\Mcp\Fixtures\StructuredContentTool;
use Hypervel\Tests\Mcp\TestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

class ToolSearchTest extends TestCase
{
    public function testSupportsDeclaringASearchableToolGroupOnTheServer(): void
    {
        $server = new class(new FakeTransporter) extends Server {
            protected array $tools = [
                StructuredContentTool::class,
                ToolSearch::class => [
                    SayHiTool::class,
                ],
            ];
        };

        $context = $server->createContext();
        $tools = $context->tools();
        $searchTools = $tools->first(fn (Tool $tool): bool => $tool->name() === 'search_tools');

        $this->assertSame([
            'structured-content-tool',
            'search_tools',
            'execute_tools',
        ], $tools->map(fn (Tool $tool): string => $tool->name())->all());
        $this->assertInstanceOf(SearchTools::class, $searchTools);

        if (! $searchTools instanceof Tool) {
            throw new LogicException('The search tool was not registered.');
        }

        $result = callContextTool($context, $searchTools, ['query' => 'person name']);

        $this->assertSame('say-hi-tool', $result['payload']['tools'][0]['name']);
    }

    public function testSearchesHiddenToolsAndReturnsTheirCompleteSchemas(): void
    {
        $context = toolSearchContext([
            SayHiTool::class,
            StructuredContentTool::class,
        ]);
        $searchTools = toolFromContext($context, 'search_tools');

        $result = callContextTool($context, $searchTools, [
            'query' => 'person name',
        ]);

        $this->assertInstanceOf(SearchTools::class, $searchTools);
        $this->assertSame('search_tools', $searchTools->name());
        $this->assertFalse($result['isError']);
        $this->assertEquals([
            'ok' => true,
            'tools' => [[
                'name' => 'say-hi-tool',
                'description' => 'This tool says hello to a person',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'The name of the person to greet',
                        ],
                    ],
                    'required' => ['name'],
                ],
            ]],
            'hasMore' => false,
        ], $result['payload']);
    }

    public function testIncludesToolAnnotationsInSearchResults(): void
    {
        $annotatedTool = new #[IsDestructive] class extends Tool {
            protected string $name = 'delete-thing-tool';

            protected string $description = 'Deletes a thing';

            /**
             * Return the deletion result.
             */
            public function handle(): Response
            {
                return Response::text('deleted');
            }
        };

        $context = toolSearchContext([$annotatedTool]);
        $searchTools = toolFromContext($context, 'search_tools');

        $result = callContextTool($context, $searchTools, ['query' => 'delete']);

        $this->assertSame(['destructiveHint' => true], $result['payload']['tools'][0]['annotations']);
    }

    public function testExecutesMultipleIndependentToolsSynchronously(): void
    {
        $context = toolSearchContext([
            SayHiTool::class,
            StructuredContentTool::class,
        ]);
        $executeTools = toolFromContext($context, 'execute_tools');

        $result = callContextTool($context, $executeTools, [
            'calls' => [
                ['name' => 'say-hi-tool', 'arguments' => ['name' => 'Taylor']],
                ['name' => 'structured-content-tool', 'arguments' => []],
            ],
        ]);

        $this->assertInstanceOf(ExecuteTools::class, $executeTools);
        $this->assertSame('execute_tools', $executeTools->name());
        $this->assertFalse($result['isError']);
        $this->assertSame([
            'ok' => true,
            'results' => [
                [
                    'name' => 'say-hi-tool',
                    'content' => [['type' => 'text', 'text' => 'Hello, Taylor!']],
                    'isError' => false,
                ],
                [
                    'name' => 'structured-content-tool',
                    'content' => [],
                    'isError' => false,
                    'structuredContent' => [
                        'temperature' => 22.5,
                        'conditions' => 'Partly cloudy',
                        'humidity' => 65,
                    ],
                ],
            ],
        ], $result['payload']);
    }

    public function testKeepsTheTextAToolReturnsAlongsideItsStructuredContent(): void
    {
        $summaryTool = new class extends Tool {
            protected string $name = 'summary-tool';

            /**
             * Return text and structured results together.
             */
            public function handle(): ResponseFactory
            {
                return Response::make(Response::text('Found 2 results'))->withStructuredContent(['count' => 2]);
            }
        };

        $context = toolSearchContext([$summaryTool]);
        $executeTools = toolFromContext($context, 'execute_tools');

        $result = callContextTool($context, $executeTools, [
            'calls' => [['name' => 'summary-tool', 'arguments' => []]],
        ]);

        $this->assertSame([[
            'name' => 'summary-tool',
            'content' => [['type' => 'text', 'text' => 'Found 2 results']],
            'isError' => false,
            'structuredContent' => ['count' => 2],
        ]], $result['payload']['results']);
    }

    public function testCountsStructuredContentOnceAgainstTheOutputLimit(): void
    {
        config(['mcp.tool_search.max_output_bytes' => 256]);

        $structuredTool = new class extends Tool {
            protected string $name = 'structured-tool';

            /**
             * Return the structured result.
             */
            public function handle(): ResponseFactory
            {
                return Response::structured(['value' => str_repeat('x', 120)]);
            }
        };

        $context = toolSearchContext([$structuredTool]);
        $executeTools = toolFromContext($context, 'execute_tools');

        $result = callContextTool($context, $executeTools, [
            'calls' => [['name' => 'structured-tool', 'arguments' => []]],
        ]);

        $this->assertFalse($result['isError']);
        $this->assertSame([
            'ok' => true,
            'results' => [[
                'name' => 'structured-tool',
                'content' => [],
                'isError' => false,
                'structuredContent' => ['value' => str_repeat('x', 120)],
            ]],
        ], $result['payload']);
    }

    public function testDropsTheStructuredContentTextFromStreamedToolResults(): void
    {
        $streamedTool = new class extends Tool {
            protected string $name = 'streamed-structured-tool';

            /**
             * Stream progress and a structured result.
             */
            public function handle(): Generator
            {
                yield Response::notification('progress', ['step' => 1]);

                yield Response::structured(['count' => 2]);
            }
        };

        $context = toolSearchContext([$streamedTool]);
        $executeTools = toolFromContext($context, 'execute_tools');

        $result = callContextTool($context, $executeTools, [
            'calls' => [['name' => 'streamed-structured-tool', 'arguments' => []]],
        ]);

        $this->assertSame([[
            'name' => 'streamed-structured-tool',
            'content' => [],
            'isError' => false,
            'structuredContent' => ['count' => 2],
        ]], $result['payload']['results']);
    }

    public function testStopsExecutingAfterTheFirstToolError(): void
    {
        $calls = [];

        $recordingTool = new class($calls) extends Tool {
            /**
             * Keep the call recorder.
             */
            public function __construct(private array &$calls)
            {
            }

            /**
             * Record the invocation and return its value.
             */
            public function handle(Request $request): Response
            {
                $this->calls[] = $request->get('value');

                return Response::text((string) $request->get('value'));
            }

            /**
             * Define the value argument.
             */
            public function schema(JsonSchema $schema): array
            {
                return ['value' => $schema->string()->required()];
            }
        };

        $context = toolSearchContext([
            SayHiTool::class,
            $recordingTool,
        ]);
        $executeTools = toolFromContext($context, 'execute_tools');

        $result = callContextTool($context, $executeTools, [
            'calls' => [
                ['name' => 'say-hi-tool', 'arguments' => []],
                ['name' => $recordingTool->name(), 'arguments' => ['value' => 'not-run']],
            ],
        ]);

        $this->assertTrue($result['isError']);
        $this->assertFalse($result['payload']['ok']);
        $this->assertCount(1, $result['payload']['results']);
        $this->assertTrue($result['payload']['results'][0]['isError']);
        $this->assertSame([], $calls);
    }

    public function testForwardsNestedToolNotifications(): void
    {
        $context = toolSearchContext([StreamingTool::class]);
        $executeTools = toolFromContext($context, 'execute_tools');
        $responses = callContextToolResponses($context, $executeTools, [
            'calls' => [[
                'name' => 'streaming-tool',
                'arguments' => ['count' => 2],
            ]],
        ]);

        $this->assertCount(3, $responses);
        $this->assertSame('stream/progress', $responses[0]['method']);
        $this->assertSame('stream/progress', $responses[1]['method']);
        $this->assertTrue(json_decode($responses[2]['result']['content'][0]['text'], true)['ok']);
    }

    public function testEnforcesTheConfiguredCallAndOutputLimits(): void
    {
        config(['mcp.tool_search.max_tool_calls' => 1, 'mcp.tool_search.max_output_bytes' => 256]);

        $context = toolSearchContext([SayHiTool::class]);
        $executeTools = toolFromContext($context, 'execute_tools');

        $callLimit = callContextTool($context, $executeTools, [
            'calls' => [
                ['name' => 'say-hi-tool', 'arguments' => ['name' => 'One']],
                ['name' => 'say-hi-tool', 'arguments' => ['name' => 'Two']],
            ],
        ]);

        $outputLimit = callContextTool($context, $executeTools, [
            'calls' => [[
                'name' => 'say-hi-tool',
                'arguments' => ['name' => str_repeat('x', 256)],
            ]],
        ]);

        $this->assertTrue($callLimit['isError']);
        $this->assertStringContainsString('The calls field must not have more than 1 items.', $callLimit['content'][0]['text']);
        $this->assertSame([
            'ok' => false,
            'error' => [
                'kind' => 'OutputLimitExceeded',
                'message' => 'The tool output exceeded 256 bytes.',
            ],
            'completedToolCalls' => 1,
            'attemptedToolCalls' => 1,
        ], $outputLimit['payload']);
    }

    public function testExpandsIntoTwoToolsAndRejectsDuplicateCatalogNames(): void
    {
        $context = toolSearchContext([
            SayHiTool::class,
            new SayHiTool,
        ]);
        $searchTools = toolFromContext($context, 'search_tools');
        $executeTools = toolFromContext($context, 'execute_tools');

        $result = callContextTool($context, $searchTools);

        $this->assertSame(['search_tools', 'execute_tools'], [$searchTools->name(), $executeTools->name()]);
        $this->assertTrue($result['isError']);
        $this->assertSame('Duplicate tool name [say-hi-tool] in ToolSearch catalog.', $result['content'][0]['text']);
    }

    public function testRejectsGeneratedToolNameCollisions(): void
    {
        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test Server', '1.0.0'),
            instructions: 'Test instructions',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: [
                ToolSearch::class => [SayHiTool::class],
                new class extends Tool {
                    protected string $name = 'search_tools';

                    /**
                     * Return the colliding tool's result.
                     */
                    public function handle(): Response
                    {
                        return Response::text('collision');
                    }
                },
            ],
            resources: [],
            prompts: [],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate server tool name [search_tools].');
        $context->tools();
    }

    public function testNullSearchLimitUsesTheDefaultInsteadOfReturningNoTools(): void
    {
        $context = toolSearchContext([SayHiTool::class]);
        $result = callContextTool($context, toolFromContext($context, 'search_tools'), ['limit' => null]);

        $this->assertFalse($result['isError']);
        $this->assertSame(['say-hi-tool'], array_column($result['payload']['tools'], 'name'));
    }

    #[DataProvider('wireArguments')]
    public function testNestedExecutionPreservesNumericObjectKeysAndRejectsLists(string $arguments, bool $isError): void
    {
        $tool = new class extends Tool {
            protected string $name = 'numeric_arguments';

            /**
             * Define a numeric property name supported by JSON Schema.
             */
            public function schema(JsonSchema $schema): array
            {
                return ['0' => $schema->string()->required()];
            }

            /**
             * Return the numeric-keyed argument.
             */
            public function handle(Request $request): Response
            {
                $request->validate(['0' => 'required|string']);

                return Response::text($request->get('0'));
            }
        };

        $transport = new ArrayTransport;
        $server = new class($transport, $tool) extends Server {
            /**
             * Register the searchable numeric-argument tool.
             */
            public function __construct(Transport $transport, Tool $tool)
            {
                parent::__construct($transport);
                $this->tools = [ToolSearch::class => [$tool]];
            }
        };

        $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"execute_tools","arguments":{"calls":[{"name":"numeric_arguments","arguments":' . $arguments . '}]}}}');

        $result = json_decode($transport->sent[0], true, flags: JSON_THROW_ON_ERROR)['result'];
        $this->assertSame($isError, $result['isError']);

        if ($isError) {
            $this->assertStringContainsString('The arguments field must be an object.', $result['content'][0]['text']);
        } else {
            $payload = json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('value', $payload['results'][0]['content'][0]['text']);
        }
    }

    /**
     * Supply wire values with the same associative PHP decoding but different JSON shapes.
     */
    public static function wireArguments(): array
    {
        return [['{"0":"value"}', false], ['["value"]', true]];
    }
}

/**
 * Create a context with one searchable tool group.
 */
function toolSearchContext(array $tools): ServerContext
{
    return new ServerContext(
        supportedProtocolVersions: ['2025-03-26'],
        serverCapabilities: [],
        implementation: new Implementation('Test Server', '1.0.0'),
        instructions: 'Test instructions',
        maxPaginationLength: 50,
        defaultPaginationLength: 10,
        tools: [ToolSearch::class => $tools],
        resources: [],
        prompts: [],
    );
}

/**
 * Find a generated tool by name.
 */
function toolFromContext(ServerContext $context, string $name): Tool
{
    $tool = $context->tools()->first(fn (Tool $tool): bool => $tool->name() === $name);

    if (! $tool instanceof Tool) {
        throw new LogicException("Tool [{$name}] was not registered.");
    }

    return $tool;
}

/**
 * Invoke a tool and decode its JSON text result.
 */
function callContextTool(ServerContext $context, Tool $tool, array $arguments = []): array
{
    $responses = callContextToolResponses($context, $tool, $arguments);
    $result = $responses[array_key_last($responses)]['result'];

    if (isset($result['content'][0]['text'])) {
        $payload = json_decode($result['content'][0]['text'], true);

        if (is_array($payload)) {
            $result['payload'] = $payload;
        }
    }

    return $result;
}

/**
 * Consume a tool's responses under its request context.
 */
function callContextToolResponses(ServerContext $context, Tool $tool, array $arguments = []): array
{
    $request = JsonRpcRequest::from([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => $tool->name(),
            'arguments' => $arguments,
        ],
    ]);

    return RequestContext::run($request->toRequest(), static function () use ($request, $context): array {
        $response = (new CallTool)->handle($request, $context);
        $responses = $response instanceof JsonRpcResponse ? [$response] : iterator_to_array($response);

        return array_map(fn (JsonRpcResponse $response): array => $response->toArray(), $responses);
    });
}
