<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Tests\Mcp\Fixtures\Client\FakeTransport;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Tests\Mcp\Fixtures\discoverResponse;
use function Hypervel\Tests\Mcp\Fixtures\initializeResponse;
use function Hypervel\Tests\Mcp\Fixtures\methodNotFoundResponse;

class HandshakeTest extends TestCase
{
    public function testProbesDiscoveryFirstAndStaysModernAgainstAModernServer(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = discoverResponse();

        $client = new Client($transport);
        $client->connect();

        $this->assertSame(ProtocolVersion::LATEST, $client->protocolVersion());
        $this->assertNull($client->initializeResult());
        $this->assertSame([ProtocolVersion::LATEST->value], $client->discoverResult()?->supportedVersions);
        $this->assertSame('Test Server', $client->discoverResult()?->serverInfo?->name);
        $this->assertSame('Be nice.', $client->discoverResult()?->instructions);

        $this->assertCount(1, $transport->sent);
        $this->assertSame('server/discover', json_decode($transport->sent[0], true)['method']);
        $this->assertSame(ProtocolVersion::LATEST, $transport->protocolVersion);
    }

    public function testFallsBackToTheHandshakeWhenDiscoveryIsNotImplemented(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = methodNotFoundResponse();
        $transport->responses[] = initializeResponse(2);

        $client = new Client($transport);
        $client->connect();

        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
        $this->assertNull($client->discoverResult());
        $this->assertSame('Test Server', $client->initializeResult()?->serverInfo->name);

        $this->assertCount(3, $transport->sent);
        $this->assertSame('server/discover', json_decode($transport->sent[0], true)['method']);
        $this->assertSame('initialize', json_decode($transport->sent[1], true)['method']);
        $this->assertSame('notifications/initialized', json_decode($transport->sent[2], true)['method']);
        $this->assertSame(ProtocolVersion::V2025_11_25, $transport->protocolVersion);
    }

    public function testFallsBackToTheHandshakeWhenDiscoveryIsRejectedWithAServerDefinedError(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32000,
                'message' => 'Bad Request: Unsupported protocol version: 2026-07-28 (supported versions: 2025-11-25, 2025-06-18)',
            ],
        ]);
        $transport->responses[] = initializeResponse(2);

        $client = new Client($transport);
        $client->connect();

        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
        $this->assertNull($client->discoverResult());
        $this->assertSame('Test Server', $client->initializeResult()?->serverInfo->name);

        $this->assertCount(3, $transport->sent);
        $this->assertSame('server/discover', json_decode($transport->sent[0], true)['method']);
        $this->assertSame('initialize', json_decode($transport->sent[1], true)['method']);
    }

    public function testFallsBackToTheHandshakeWhenTheDiscoveryProbeTimesOut(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->timesOutOnDiscover = true;
        $transport->responses[] = initializeResponse();

        $client = new Client($transport);
        $client->connect();

        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
        $this->assertNull($client->discoverResult());
        $this->assertTrue($transport->connected);

        $this->assertCount(2, $transport->sent);
        $this->assertSame('initialize', json_decode($transport->sent[0], true)['method']);
        $this->assertSame('notifications/initialized', json_decode($transport->sent[1], true)['method']);
    }

    public function testNeverSendsADiscoveryEraVersionOrMetadataThroughTheHandshake(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = methodNotFoundResponse();
        $transport->responses[] = initializeResponse(2);

        (new Client($transport))->connect();

        $initialize = json_decode($transport->sent[1], true);

        $this->assertSame(ProtocolVersion::V2025_11_25->value, $initialize['params']['protocolVersion']);
        $this->assertArrayNotHasKey('_meta', $initialize['params']);
    }

    public function testDoesNotFallBackWhenTheProbeFailsForAReasonOtherThanAMissingMethod(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => -32603, 'message' => 'Internal error'],
        ]);

        try {
            (new Client($transport))->connect();
            $this->fail('Expected the server error.');
        } catch (JsonRpcException $exception) {
            $this->assertStringContainsString('Internal error', $exception->getMessage());
        }

        $this->assertCount(1, $transport->sent);
    }

    public function testRejectsAServerThatWillNotSettleOnTheRequestedVersion(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = discoverResponse(1, ['2025-11-25']);
        $transport->responses[] = initializeResponse(2);

        $client = (new Client($transport))->withProtocolVersion(ProtocolVersion::LATEST);

        try {
            $client->connect();
            $this->fail('Expected a version mismatch.');
        } catch (ClientException $exception) {
            $this->assertSame('The server settled on protocol version [2025-11-25] while [2026-07-28] was requested.', $exception->getMessage());
        }

        $this->assertCount(1, $transport->sent);
    }

    public function testDoesNotFallBackWhenTheErrorIdentifiesAModernServer(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => -32020, 'message' => 'Header mismatch: The [Mcp-Method] header is required.'],
        ]);

        try {
            (new Client($transport))->connect();
            $this->fail('Expected the header mismatch.');
        } catch (JsonRpcException $exception) {
            $this->assertStringContainsString('Header mismatch', $exception->getMessage());
        }

        $this->assertCount(1, $transport->sent);
    }

    public function testRetriesWithAMutuallySupportedVersionFromAnUnsupportedVersionError(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32022,
                'message' => 'Unsupported protocol version',
                'data' => ['supported' => ['2025-11-25', '2025-06-18'], 'requested' => '2026-07-28'],
            ],
        ]);
        $transport->responses[] = initializeResponse(2);

        $client = new Client($transport);
        $client->connect();

        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
        $this->assertSame(ProtocolVersion::V2025_11_25->value, json_decode($transport->sent[1], true)['params']['protocolVersion']);
    }

    #[TestWith([['supported' => ['2027-01-01'], 'requested' => '2026-07-28']])]
    #[TestWith(['No supported protocol version'])]
    public function testPropagatesAnUnsupportedVersionErrorWithNoMutuallySupportedVersion(mixed $data): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32022,
                'message' => 'Unsupported protocol version',
                'data' => $data,
            ],
        ]);

        try {
            (new Client($transport))->connect();
            $this->fail('Expected an unsupported protocol version error.');
        } catch (JsonRpcException $exception) {
            $this->assertStringContainsString('Unsupported protocol version', $exception->getMessage());
            $this->assertSame($data, $exception->data());
        }

        $this->assertCount(1, $transport->sent);
    }

    public function testFallsBackToTheHandshakeWhenDiscoveryListsOnlyALegacyVersion(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = discoverResponse(1, ['2025-11-25']);
        $transport->responses[] = initializeResponse(2);

        $client = new Client($transport);
        $client->connect();

        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
        $this->assertNull($client->discoverResult());
        $this->assertSame('initialize', json_decode($transport->sent[1], true)['method']);
        $this->assertArrayNotHasKey('_meta', json_decode($transport->sent[1], true)['params']);
    }

    public function testSurfacesTheHandshakeFailureAndNotTheProbeWhenTheFallbackFails(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = methodNotFoundResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'error' => ['code' => -32603, 'message' => 'Internal error'],
        ]);

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Internal error');

        (new Client($transport))->connect();
    }

    public function testSendsTheRequiredProtocolMetadataOnEveryDiscoveryEraRequest(): void
    {
        config(['app.name' => 'Acme MCP App']);

        $transport = $this->negotiatingTransport();
        $transport->responses[] = discoverResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['tools' => [['name' => 'add', 'description' => 'Adds two numbers']]],
        ]);

        (new Client($transport))->tools();

        foreach ($transport->sent as $raw) {
            $meta = json_decode($raw, true)['params']['_meta'];

            $this->assertSame(ProtocolVersion::LATEST->value, $meta['io.modelcontextprotocol/protocolVersion']);
            $this->assertArrayHasKey('io.modelcontextprotocol/clientCapabilities', $meta);
            $this->assertSame('Acme MCP App', $meta['io.modelcontextprotocol/clientInfo']['name']);
        }
    }

    public function testSkipsTheProbeWhenADiscoveryVersionIsPinned(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = discoverResponse();

        $client = (new Client($transport))->withProtocolVersion(ProtocolVersion::LATEST);
        $client->connect();

        $this->assertSame(ProtocolVersion::LATEST, $client->protocolVersion());
        $this->assertCount(1, $transport->sent);
        $this->assertSame('server/discover', json_decode($transport->sent[0], true)['method']);
    }

    public function testOffersThePinnedLegacyVersionAndDoesNotProbeDiscovery(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = initializeResponse(1, ProtocolVersion::V2025_06_18->value);

        $client = (new Client($transport))->withProtocolVersion(ProtocolVersion::V2025_06_18);
        $client->connect();

        $this->assertSame(ProtocolVersion::V2025_06_18, $client->protocolVersion());
        $this->assertSame('initialize', json_decode($transport->sent[0], true)['method']);
        $this->assertSame(ProtocolVersion::V2025_06_18->value, json_decode($transport->sent[0], true)['params']['protocolVersion']);
    }

    public function testNegotiatesOnDemandRatherThanGuessingAProtocolVersion(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = methodNotFoundResponse();
        $transport->responses[] = initializeResponse(2);

        $client = new Client($transport);

        $this->assertFalse($client->connected());
        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
        $this->assertTrue($client->connected());
    }

    public function testRejectsAProtocolVersionThisClientDoesNotSupport(): void
    {
        $client = new Client(new FakeTransport);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('This client does not support protocol version [2024-11-05].');

        $client->withProtocolVersion(ProtocolVersion::V2024_11_05);
    }

    public function testRemembersADiscoveryEraServerAcrossReconnects(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = discoverResponse();

        $client = new Client($transport);
        $client->connect();
        $client->disconnect();

        $transport->sent = [];
        $transport->responses[] = discoverResponse(2);

        $client->connect();

        $this->assertSame(ProtocolVersion::LATEST, $client->protocolVersion());
        $this->assertCount(1, $transport->sent);
        $this->assertSame('server/discover', json_decode($transport->sent[0], true)['method']);
    }

    public function testRemembersALegacyServerAcrossReconnects(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = methodNotFoundResponse();
        $transport->responses[] = initializeResponse(2);

        $client = new Client($transport);
        $client->connect();
        $client->disconnect();

        $transport->sent = [];
        $transport->responses[] = initializeResponse(3);

        $client->connect();

        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
        $this->assertCount(2, $transport->sent);
        $this->assertSame('initialize', json_decode($transport->sent[0], true)['method']);
    }

    public function testReprobesWithinTheSameReconnectWhenARememberedDiscoveryEraServerTurnsLegacy(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = discoverResponse();

        $client = new Client($transport);
        $client->connect();
        $client->disconnect();

        $transport->sent = [];
        $transport->responses[] = methodNotFoundResponse(2);
        $transport->responses[] = initializeResponse(3);

        $client->connect();

        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
        $this->assertSame('server/discover', json_decode($transport->sent[0], true)['method']);
        $this->assertSame('initialize', json_decode($transport->sent[1], true)['method']);
    }

    public function testReprobesWithinTheSameReconnectWhenARememberedLegacyServerTurnsModern(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = methodNotFoundResponse();
        $transport->responses[] = initializeResponse(2);

        $client = new Client($transport);
        $client->connect();
        $client->disconnect();

        $transport->sent = [];
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'error' => ['code' => -32601, 'message' => 'Method not found.'],
        ]);
        $transport->responses[] = discoverResponse(4);

        $client->connect();

        $this->assertSame(ProtocolVersion::LATEST, $client->protocolVersion());
        $this->assertSame('initialize', json_decode($transport->sent[0], true)['method']);
        $this->assertSame('server/discover', json_decode($transport->sent[1], true)['method']);
    }

    public function testReconnectsWhenTheProtocolVersionIsPinnedAfterConnecting(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = methodNotFoundResponse();
        $transport->responses[] = initializeResponse(2);
        $transport->responses[] = discoverResponse(3);
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 4,
            'result' => ['tools' => []],
        ]);

        $client = new Client($transport);
        $client->connect();

        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());

        $client->withProtocolVersion(ProtocolVersion::LATEST);
        $client->tools();

        $this->assertSame(ProtocolVersion::LATEST, $client->protocolVersion());
        $this->assertSame('server/discover', json_decode($transport->sent[3], true)['method']);
        $this->assertArrayHasKey('_meta', json_decode($transport->sent[4], true)['params']);
    }

    public function testRejectsAMalformedDiscoverResponse(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['capabilities' => []],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid discover response from server.');

        (new Client($transport))->connect();
    }

    public function testRejectsAServerWithNoProtocolVersionThisClientCanSpeak(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = discoverResponse(1, ['2027-01-01']);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('The server supports protocol versions [2027-01-01]. This client supports [2026-07-28, 2025-11-25, 2025-06-18].');

        (new Client($transport))->connect();
    }

    public function testSurfacesAnInvalidParamsErrorInsteadOfGuessingTheServerIsLegacy(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => -32602, 'message' => 'Invalid params: the request [_meta] is malformed.'],
        ]);

        try {
            (new Client($transport))->connect();
            $this->fail('Expected the invalid parameters error.');
        } catch (JsonRpcException $exception) {
            $this->assertSame('Invalid params: the request [_meta] is malformed.', $exception->getMessage());
        }

        $this->assertCount(1, $transport->sent);
    }

    public function testRethrowsAnAuthorizationFailureWhenReinitializingARememberedLegacyServer(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = methodNotFoundResponse();
        $transport->responses[] = initializeResponse(2);

        $client = new Client($transport);
        $client->connect();
        $client->disconnect();

        $transport->sent = [];
        $failure = new AuthorizationRequiredException;
        $transport->throwOnNextSend = $failure;
        $caught = null;

        try {
            $client->connect();
        } catch (AuthorizationRequiredException $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $this->assertSame([], $transport->sent);
    }

    public function testExposesTheServerDetailsWithoutKnowingWhichEraWasNegotiated(): void
    {
        $modern = $this->negotiatingTransport();
        $modern->responses[] = discoverResponse();

        $legacy = $this->negotiatingTransport();
        $legacy->responses[] = methodNotFoundResponse();
        $legacy->responses[] = initializeResponse(2);

        $this->assertSame('Test Server', (new Client($modern))->serverInfo()?->name);
        $this->assertSame('Test Server', (new Client($legacy))->serverInfo()?->name);

        $another = $this->negotiatingTransport();
        $another->responses[] = discoverResponse();
        $client = new Client($another);

        $this->assertSame('Be nice.', $client->instructions());
        $this->assertIsArray($client->capabilities());
    }

    public function testFallsBackToTheHandshakeWhenDiscoveryIsRejectedWithANullIdError(): void
    {
        $transport = $this->negotiatingTransport();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32000,
                'message' => 'Bad Request: Unsupported protocol version: 2026-07-28 (supported versions: 2025-11-25, 2025-06-18)',
            ],
        ]);
        $transport->responses[] = initializeResponse(2);

        $client = new Client($transport);
        $client->connect();

        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
        $this->assertSame('Test Server', $client->initializeResult()?->serverInfo->name);

        $this->assertCount(3, $transport->sent);
        $this->assertSame('server/discover', json_decode($transport->sent[0], true)['method']);
        $this->assertSame('initialize', json_decode($transport->sent[1], true)['method']);
    }

    public function testRechecksTheConnectionWhenAWaitingCallerPinsItsVersion(): void
    {
        $transport = new class extends FakeTransport {
            public ?Closure $beforeReceive = null;

            /**
             * Run the pending interleaving before returning the first response.
             */
            public function receive(): string
            {
                $callback = $this->beforeReceive;
                $this->beforeReceive = null;
                $callback?->__invoke();

                return parent::receive();
            }
        };
        $transport->negotiates = true;
        $transport->responses = [discoverResponse(), initializeResponse(2)];
        $client = new Client($transport);
        $child = null;
        $pinned = false;
        $transport->beforeReceive = function () use ($client, &$child, &$pinned): void {
            $child = Coroutine::create(function () use ($client, &$pinned): void {
                $client->withProtocolVersion(ProtocolVersion::V2025_11_25);
                $pinned = true;
            });
        };

        try {
            $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
            $this->assertTrue($pinned);
            $this->assertTrue($client->connected());
            $this->assertSame('initialize', json_decode($transport->sent[1], true)['method']);
        } finally {
            if ($child !== null) {
                Coroutine::join([$child], 5);
                if (Coroutine::exists($child)) {
                    EngineCoroutine::cancelById($child, true);
                    Coroutine::join([$child], 5);
                }
            }
        }
    }

    public function testCancellationDuringDisconnectTakesPrecedenceOverARequestFailure(): void
    {
        $cancellation = new CanceledException('Disconnect canceled.');
        $transport = new class($cancellation) extends FakeTransport {
            public bool $disconnected = false;

            /**
             * Create a transport whose cleanup is cancelled.
             */
            public function __construct(private CanceledException $cancellation)
            {
            }

            /**
             * Disconnect and propagate cleanup cancellation.
             */
            public function disconnect(): void
            {
                parent::disconnect();
                $this->disconnected = true;

                throw $this->cancellation;
            }
        };
        $transport->negotiates = true;
        $transport->responses = [discoverResponse()];
        $client = new Client($transport);
        $client->connect();
        $transport->throwOnNextSend = new RuntimeException('Request failed.');
        $caught = null;

        try {
            $client->ping();
        } catch (CanceledException $exception) {
            $caught = $exception;
        }

        $this->assertSame($cancellation, $caught);
        $this->assertTrue($transport->disconnected);
        $this->assertFalse($client->connected());
    }

    /**
     * Create a transport that responds to discovery.
     */
    private function negotiatingTransport(): FakeTransport
    {
        $transport = new FakeTransport;
        $transport->negotiates = true;

        return $transport;
    }
}
