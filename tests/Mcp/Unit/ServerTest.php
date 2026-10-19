<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit;

use Exception;
use Generator;
use Hypervel\Context\CoroutineContext;
use Hypervel\Mcp\Enums\IconTheme;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Schema\Icon;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Attributes\Icon as IconAttribute;
use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\Fixtures\ArrayTransport;
use Hypervel\Tests\Mcp\Fixtures\CustomMethodHandler;
use Hypervel\Tests\Mcp\Fixtures\ExampleServer;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Tests\Mcp\Fixtures\callStreamingToolMessage;
use function Hypervel\Tests\Mcp\Fixtures\callToolMessage;
use function Hypervel\Tests\Mcp\Fixtures\discoverMessage;
use function Hypervel\Tests\Mcp\Fixtures\expectedCallToolResponse;
use function Hypervel\Tests\Mcp\Fixtures\expectedDiscoverResponse;
use function Hypervel\Tests\Mcp\Fixtures\expectedListToolsResponse;
use function Hypervel\Tests\Mcp\Fixtures\expectedStreamingToolResponse;
use function Hypervel\Tests\Mcp\Fixtures\initializeMessage;
use function Hypervel\Tests\Mcp\Fixtures\listToolsMessage;
use function Hypervel\Tests\Mcp\Fixtures\protocolMeta;

class ServerTest extends TestCase
{
    #[DataProvider('legacyInitializeVersions')]
    public function testAnswersTheLegacyInitializeHandshake(string $version): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        ($transport->handler)(json_encode([...initializeMessage(), 'params' => ['protocolVersion' => $version]]));

        $response = json_decode((string) $transport->sent[0], true);

        $this->assertSame(456, $response['id']);
        $this->assertSame($version, $response['result']['protocolVersion']);
        $this->assertSame('Hypervel MCP Server', $response['result']['serverInfo']['name']);
        $this->assertArrayHasKey('capabilities', $response['result']);
        $this->assertArrayHasKey('instructions', $response['result']);

        ($transport->handler)(json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        $this->assertCount(1, $transport->sent);

        ($transport->handler)(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']));

        $response = json_decode((string) $transport->sent[1], true);

        $this->assertContains('say-hi-tool', array_column($response['result']['tools'], 'name'));

        $message = callToolMessage();
        unset($message['params']['_meta']);

        ($transport->handler)(json_encode($message));

        $response = json_decode((string) $transport->sent[2], true);

        $this->assertSame('Hello, John Doe!', $response['result']['content'][0]['text']);
    }

    /**
     * Provide the protocol versions supported by the legacy initialize handshake.
     */
    public static function legacyInitializeVersions(): array
    {
        return [['2025-11-25'], ['2025-06-18'], ['2025-03-26']];
    }

    public function testFallsBackToTheLatestLegacyVersionForAnUnknownInitializeVersion(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        ($transport->handler)(json_encode([...initializeMessage(), 'params' => ['protocolVersion' => '2024-11-05']]));

        $this->assertSame('2025-11-25', json_decode((string) $transport->sent[0], true)['result']['protocolVersion']);
    }

    public function testFallsBackToTheLatestLegacyVersionForAMalformedInitializeVersion(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        ($transport->handler)(json_encode([...initializeMessage(), 'params' => ['protocolVersion' => 1]]));

        $this->assertSame('2025-11-25', json_decode((string) $transport->sent[0], true)['result']['protocolVersion']);
    }

    public function testServesALegacyRequestWithoutProtocolMetadata(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        ($transport->handler)(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]));

        $this->assertNotEmpty(json_decode((string) $transport->sent[0], true)['result']['tools']);
    }

    public function testCanHandleADiscoverMessage(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        $payload = json_encode(discoverMessage());

        ($transport->handler)($payload);

        $response = json_decode((string) $transport->sent[0], true);

        $this->assertEquals(expectedDiscoverResponse(), $response);
    }

    #[DataProvider('modernOnlyMethods')]
    public function testRequiresMetadataForModernOnlyMethods(string $method): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);
        $server->start();

        ($transport->handler)(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method]));

        $response = json_decode($transport->sent[0], true);
        $this->assertSame(1, $response['id']);
        $this->assertSame(-32602, $response['error']['code']);
    }

    /**
     * Provide methods that cannot use legacy request semantics.
     */
    public static function modernOnlyMethods(): array
    {
        return [['server/discover'], ['subscriptions/listen']];
    }

    #[DataProvider('invalidProtocolMetadata')]
    public function testRejectsARequestWithoutTheRequiredProtocolMetadata(array $meta, string $message): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => $meta],
        ]);

        ($transport->handler)($payload);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32602,
                'message' => $message,
            ],
        ], json_decode((string) $transport->sent[0], true));
    }

    /**
     * Supply missing and malformed protocol metadata.
     */
    public static function invalidProtocolMetadata(): array
    {
        return [
            'missing version' => [
                ['io.modelcontextprotocol/clientCapabilities' => []],
                'Invalid params: The request [_meta] is missing the required [io.modelcontextprotocol/protocolVersion] member.',
            ],
            'missing capabilities' => [
                ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'],
                'Invalid params: The request [_meta] is missing the required [io.modelcontextprotocol/clientCapabilities] member.',
            ],
            'null version' => [
                [
                    'io.modelcontextprotocol/protocolVersion' => null,
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
                'Invalid params: The request [_meta] is missing the required [io.modelcontextprotocol/protocolVersion] member.',
            ],
            'non-string version' => [
                [
                    'io.modelcontextprotocol/protocolVersion' => 123,
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
                'Invalid params: The request [_meta] is missing the required [io.modelcontextprotocol/protocolVersion] member.',
            ],
            'non-array capabilities' => [
                [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => 'nope',
                ],
                'Invalid params: The request [_meta] is missing the required [io.modelcontextprotocol/clientCapabilities] member.',
            ],
            'list capabilities' => [
                [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => ['elicitation'],
                ],
                'Invalid params: The request [_meta] is missing the required [io.modelcontextprotocol/clientCapabilities] member.',
            ],
        ];
    }

    public function testRejectsAnUnsupportedProtocolVersion(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2025-11-25',
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
            ],
        ]);

        ($transport->handler)($payload);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32022,
                'message' => 'Unsupported protocol version',
                'data' => [
                    'supported' => ['2026-07-28'],
                    'requested' => '2025-11-25',
                ],
            ],
        ], json_decode((string) $transport->sent[0], true));
    }

    public function testCanAddACapability(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->addCapability('customFeature.enabled', true);
        $server->addCapability('anotherFeature');

        $server->start();

        $payload = json_encode(discoverMessage());

        ($transport->handler)($payload);

        $jsonResponse = $transport->sent[0];

        $capabilities = (fn (): array => $this->capabilities)->call($server);

        $expectedCapabilitiesJson = json_encode(array_merge($capabilities, [
            'customFeature' => [
                'enabled' => true,
            ],
            'anotherFeature' => (object) [],
        ]));

        $this->assertStringContainsString($expectedCapabilitiesJson, $jsonResponse);
    }

    public function testCanHandleAListToolsMessage(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        $payload = json_encode(listToolsMessage());

        ($transport->handler)($payload);

        $response = json_decode((string) $transport->sent[0], true);

        $this->assertEquals(expectedListToolsResponse(), $response);
    }

    public function testCanHandleACallToolMessage(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        $payload = json_encode(callToolMessage());

        ($transport->handler)($payload);

        $response = json_decode((string) $transport->sent[0], true);

        $this->assertEquals(expectedCallToolResponse(), $response);
    }

    public function testCanHandleANotificationMessage(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        ($transport->handler)($payload);

        $this->assertCount(0, $transport->sent);
    }

    public function testRejectsARequestWithANullIdInsteadOfTreatingItAsANotification(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'method' => 'tools/list',
        ]);

        ($transport->handler)($payload);

        $this->assertCount(1, $transport->sent);

        $response = json_decode((string) $transport->sent[0], true);

        $this->assertSame(-32600, $response['error']['code']);
        $this->assertSame('Invalid Request: The [id] member must be a string, number.', $response['error']['message']);
        $this->assertArrayNotHasKey('result', $response);
    }

    public function testCanHandleAnUnknownMethod(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 789,
            'method' => 'unknown/method',
            'params' => ['_meta' => protocolMeta()],
        ]);

        ($transport->handler)($payload);

        $response = json_decode((string) $transport->sent[0], true);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'id' => 789,
            'error' => [
                'code' => -32601,
                'message' => 'The method [unknown/method] was not found.',
            ],
        ], $response);
    }

    #[DataProvider('invalidParameterShapes')]
    public function testReturnsProtocolErrorsForInvalidParameterShapes(mixed $params, string $message): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 272,
            'method' => 'tools/call',
            'params' => $params,
        ]);

        ($transport->handler)($payload);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'id' => 272,
            'error' => [
                'code' => -32602,
                'message' => $message,
            ],
        ], json_decode((string) $transport->sent[0], true));
    }

    /**
     * Supply invalid parameter shapes.
     */
    public static function invalidParameterShapes(): array
    {
        return [
            'top-level params' => ['invalid', 'Invalid params: The [params] member must be an object.'],
            'tool arguments' => [[
                'name' => 'say-hi-tool',
                'arguments' => 'invalid',
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
            ], 'Invalid params: The [arguments] member must be an object.'],
        ];
    }

    public function testHandlesJsonDecodeErrors(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        $invalidJsonPayload = '{"jsonrpc": "2.0", "id": 123, "method": "initialize", "params": {}';

        // Malformed JSON
        ($transport->handler)($invalidJsonPayload);

        $this->assertCount(1, $transport->sent);
        $response = json_decode((string) $transport->sent[0], true);

        $this->assertSame([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32700,
                'message' => 'Parse error: Invalid JSON was received by the server.',
            ],
        ], $response);
    }

    public function testCanHandleACustomMethodMessage(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->addMethod('custom/method', CustomMethodHandler::class);

        $this->app->bind(CustomMethodHandler::class, fn (): CustomMethodHandler => new CustomMethodHandler('custom-dependency'));

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 12345,
            'method' => 'custom/method',
            'params' => ['_meta' => protocolMeta()],
        ]);

        ($transport->handler)($payload);

        $this->assertCount(1, $transport->sent);
        $response = json_decode((string) $transport->sent[0], true);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'id' => 12345,
            'result' => [
                'resultType' => 'complete',
                'message' => 'Custom method executed successfully!',
                '_meta' => [
                    'io.modelcontextprotocol/serverInfo' => [
                        'name' => 'Hypervel MCP Server',
                        'version' => '0.0.1',
                    ],
                ],
            ],
        ], $response);
    }

    public function testKeepsTheResultTypeAndMetadataAMethodSuppliedItself(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->addMethod('custom/method', OpinionatedMethodHandler::class);

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'custom/method',
            'params' => ['_meta' => protocolMeta()],
        ]);

        ($transport->handler)($payload);

        $this->assertEquals([
            'resultType' => 'input_required',
            '_meta' => [
                'io.modelcontextprotocol/serverInfo' => [
                    'name' => 'Hypervel MCP Server',
                    'version' => '0.0.1',
                ],
                'app/trace' => 'abc',
            ],
        ], json_decode((string) $transport->sent[0], true)['result']);
    }

    public function testAnswersALegacyPingMessage(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 789,
            'method' => 'ping',
        ]);

        ($transport->handler)($payload);

        $response = json_decode((string) $transport->sent[0], true);

        $this->assertSame(789, $response['id']);
        $this->assertArrayNotHasKey('error', $response);
        $this->assertIsArray($response['result']);
    }

    public function testLetsAddMethodOverrideTheBuiltInInitializeHandler(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->addMethod('initialize', CustomMethodHandler::class);

        $this->app->bind(CustomMethodHandler::class, fn (): CustomMethodHandler => new CustomMethodHandler('custom-dependency'));

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 456,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) []],
        ]);

        ($transport->handler)($payload);

        $response = json_decode((string) $transport->sent[0], true);

        $this->assertSame('Custom method executed successfully!', $response['result']['message']);
    }

    public function testDiscoversAnEmptyCapabilitySetAsAJsonObject(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        (function (): void {
            $this->capabilities = [];
        })->call($server);

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 456,
            'method' => 'server/discover',
            'params' => ['_meta' => protocolMeta()],
        ]);

        ($transport->handler)($payload);

        $this->assertStringContainsString('"capabilities":{}', $transport->sent[0]);
    }

    public function testCallsBootMethodOnConnect(): void
    {
        $transport = new ArrayTransport;

        $server = new class($transport) extends Server {
            public bool $bootCalled = false;

            /**
             * Record the server boot.
             */
            public function boot(): void
            {
                $this->bootCalled = true;
            }
        };
        $server->start();

        $this->assertTrue($server->bootCalled, 'The boot() method was not called on connect.');
    }

    public function testCanHandleAToolStreamingMultipleMessages(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        $payload = json_encode(callStreamingToolMessage());

        ($transport->handler)($payload);

        $messages = array_map(fn (string $message): mixed => json_decode($message, true), $transport->sent);

        $this->assertEquals(expectedStreamingToolResponse(), $messages);
    }

    public function testHandlesCapabilityWithNonArrayExistingValue(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        // First set a non-array value
        $server->addCapability('feature');

        // Then try to add a nested capability to it
        $server->addCapability('feature.enabled', true);

        $server->start();

        $payload = json_encode(discoverMessage());

        ($transport->handler)($payload);

        $capabilities = (fn (): array => $this->capabilities)->call($server);

        $this->assertIsArray($capabilities['feature']);
        $this->assertTrue($capabilities['feature']['enabled']);
    }

    public function testHandlesExceptionsInDebugMode(): void
    {
        config(['app.debug' => true]);

        $transport = new ArrayTransport;
        $server = new class($transport) extends Server {
            protected array $methods = [
                'test/method' => ThrowingMethodHandler::class,
            ];
        };

        $this->app->bind(ThrowingMethodHandler::class, fn (): Method => new class implements Method {
            /**
             * Fail the method invocation.
             */
            public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
            {
                throw new Exception('Test exception');
            }
        });

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 999,
            'method' => 'test/method',
            'params' => ['_meta' => protocolMeta()],
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Test exception');
        ($transport->handler)($payload);
    }

    public function testHandlesExceptionsInProductionMode(): void
    {
        config(['app.debug' => false]);

        $transport = new ArrayTransport;
        $server = new class($transport) extends Server {
            protected array $methods = [
                'test/method' => ThrowingMethodHandler::class,
            ];
        };

        $this->app->bind(ThrowingMethodHandler::class, fn (): Method => new class implements Method {
            /**
             * Fail the method invocation.
             */
            public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
            {
                throw new Exception('Test exception');
            }
        });

        $server->start();

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 999,
            'method' => 'test/method',
            'params' => ['_meta' => protocolMeta()],
        ]);

        ($transport->handler)($payload);

        $this->assertCount(1, $transport->sent);
        $response = json_decode((string) $transport->sent[0], true);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'id' => 999,
            'error' => [
                'code' => -32603,
                'message' => 'Something went wrong while processing the request.',
            ],
        ], $response);
    }

    public function testForwardsIconsIntoTheServerContext(): void
    {
        $server = new class(new ArrayTransport) extends Server {
            /**
             * Return the server icon.
             */
            protected function icons(): array
            {
                return [new Icon('https://example.com/server.png', mimeType: 'image/png')];
            }
        };

        $context = $server->createContext();

        $this->assertCount(1, $context->implementation->icons);
        $this->assertInstanceOf(Icon::class, $context->implementation->icons[0]);
        $this->assertSame('https://example.com/server.png', $context->implementation->icons[0]->src);
    }

    public function testReadsClassLevelIconAttributesIntoTheServerContext(): void
    {
        $server = new IconAttributeServer(new ArrayTransport);

        $context = $server->createContext();

        $this->assertCount(2, $context->implementation->icons);
        $this->assertSame('https://example.com/icon.png', $context->implementation->icons[0]->src);
        $this->assertSame('image/png', $context->implementation->icons[0]->mimeType);
        $this->assertSame(['48x48'], $context->implementation->icons[0]->sizes);
        $this->assertSame('https://example.com/icon-dark.svg', $context->implementation->icons[1]->src);
        $this->assertSame(IconTheme::Dark, $context->implementation->icons[1]->theme);
    }

    public function testMergesIconAttributesWithIconsMethodOutput(): void
    {
        $server = new MixedIconServer(new ArrayTransport);

        $context = $server->createContext();

        $this->assertCount(2, $context->implementation->icons);
        $this->assertSame('https://example.com/from-attribute.png', $context->implementation->icons[0]->src);
        $this->assertSame('https://example.com/from-method.png', $context->implementation->icons[1]->src);
    }

    #[DataProvider('invalidEnvelopes')]
    public function testRejectsNonObjectEnvelopesAsInvalidRequests(string $payload): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);
        $server->handle($payload);

        $response = json_decode($transport->sent[0], true);
        $this->assertSame(-32600, $response['error']['code']);
    }

    /**
     * Supply valid JSON values that are not request objects.
     */
    public static function invalidEnvelopes(): array
    {
        return [['null'], ['true'], ['5'], ['"text"'], ['[]'], ['[{"jsonrpc":"2.0","id":1,"method":"ping"}]']];
    }

    #[DataProvider('terminalFailures')]
    public function testEncodesTerminalFailuresFromEagerAndLazyMethods(bool $streaming, string $failure, int $code): void
    {
        config(['app.debug' => false]);
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);
        $server->addMethod('test/failure', TerminalFailureMethod::class);
        $this->app->instance(TerminalFailureMethod::class, new TerminalFailureMethod($streaming, $failure));

        $server->handle('{"jsonrpc":"2.0","id":1,"method":"test/failure"}');

        $this->assertCount($streaming ? 2 : 1, $transport->sent);
        if ($streaming) {
            $this->assertSame('progress', json_decode($transport->sent[0], true)['method']);
        }
        $response = json_decode($transport->sent[array_key_last($transport->sent)], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $response['id']);
        $this->assertSame($code, $response['error']['code']);
        $this->assertArrayNotHasKey('result', $response);
    }

    /**
     * Supply failures at both response boundaries.
     */
    public static function terminalFailures(): array
    {
        return [
            [false, 'encoding', -32603],
            [true, 'encoding', -32603],
            [false, 'protocol', -32602],
            [true, 'protocol', -32602],
            [false, 'error-data', -32603],
            [true, 'error-data', -32603],
            [true, 'ordinary', -32603],
        ];
    }

    public function testLazyCancellationRestoresTheRequestWithoutSendingAnError(): void
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);
        $server->addMethod('test/cancel', CancellationMethod::class);
        $method = new CancellationMethod;
        $this->app->instance(CancellationMethod::class, $method);
        $outer = new Request(['outer' => true]);
        CoroutineContext::set(Request::class, $outer);

        try {
            $server->handle('{"jsonrpc":"2.0","id":1,"method":"test/cancel","params":{"arguments":{"value":"inner"}}}');
            $this->fail('Cancellation must propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($method->cancellation, $exception);
        }

        $this->assertSame('inner', $method->closedUnder?->get('value'));
        $this->assertSame($outer, CoroutineContext::get(Request::class));
        $this->assertCount(1, $transport->sent);
        $this->assertSame('progress', json_decode($transport->sent[0], true)['method']);
    }
}

#[IconAttribute('https://example.com/icon.png', mimeType: 'image/png', sizes: ['48x48'])]
#[IconAttribute('https://example.com/icon-dark.svg', theme: IconTheme::Dark)]
class IconAttributeServer extends Server
{
}

#[IconAttribute('https://example.com/from-attribute.png')]
class MixedIconServer extends Server
{
    /**
     * Return the server icon.
     */
    protected function icons(): array
    {
        return [new Icon('https://example.com/from-method.png')];
    }
}

class OpinionatedMethodHandler implements Method
{
    /**
     * Return a result with its own completion type and metadata.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, [
            'resultType' => 'input_required',
            '_meta' => ['app/trace' => 'abc'],
        ]);
    }
}

class TerminalFailureMethod implements Method
{
    /**
     * Create a method with a controlled failure.
     */
    public function __construct(private bool $streaming, private string $failure)
    {
    }

    /**
     * Return or stream the configured failure.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse|Generator
    {
        if ($this->streaming) {
            return (function () use ($request): Generator {
                yield JsonRpcResponse::notification('progress', []);
                yield $this->result($request);
            })();
        }

        return $this->result($request);
    }

    /**
     * Produce the failure at serialization or invocation.
     */
    private function result(JsonRpcRequest $request): JsonRpcResponse
    {
        return match ($this->failure) {
            'encoding' => JsonRpcResponse::result($request->id, ['value' => NAN]),
            'protocol' => throw new JsonRpcException('Invalid argument', -32602, $request->id),
            'error-data' => throw new JsonRpcException('Invalid argument', -32602, $request->id, ['value' => NAN]),
            'ordinary' => throw new Exception('Failed after progress'),
        };
    }
}

class CancellationMethod implements Method
{
    public ?Request $closedUnder = null;

    public CanceledException $cancellation;

    /**
     * Create the cancellation signal.
     */
    public function __construct()
    {
        $this->cancellation = new CanceledException('Canceled request');
    }

    /**
     * Cancel after emitting progress and record the request during cleanup.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator
    {
        try {
            yield JsonRpcResponse::notification('progress', []);
            throw $this->cancellation;
        } finally {
            $this->closedUnder = CoroutineContext::get(Request::class);
        }
    }
}
