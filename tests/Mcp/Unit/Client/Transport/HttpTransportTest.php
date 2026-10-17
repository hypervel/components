<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client\Transport;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Request;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Transport\HttpTransport;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\Exceptions\SessionExpiredException;
use Hypervel\Mcp\WebClient;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Http\Message\StreamInterface;
use stdClass;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Tests\Mcp\Fixtures\legacyEndpoint;
use function Hypervel\Tests\Mcp\Fixtures\sseStream;

class HttpTransportTest extends TestCase
{
    public function testPostsAFrameAndQueuesAJsonResponse(): void
    {
        Http::fake([
            'https://mcp.test/mcp' => Http::response(
                json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]]),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->setTimeoutSeconds(5.0);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => []]));

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]], json_decode($transport->receive(), true));

        Http::assertSent(fn ($request): bool => $request->hasHeader('Accept', 'application/json, text/event-stream'));
    }

    public function testParsesAnSseStreamIntoIndividualFramesInOrder(): void
    {
        $stream = sseStream([
            ['jsonrpc' => '2.0', 'method' => 'stream/progress', 'params' => ['progress' => 50]],
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['done' => true]],
        ]);

        Http::fake(['*' => Http::response($stream, 200, ['Content-Type' => 'text/event-stream'])]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => []]));

        $this->assertSame('stream/progress', json_decode($transport->receive(), true)['method']);
        $reply = json_decode($transport->receive(), true);
        $this->assertSame(1, $reply['id']);
        $this->assertSame(['done' => true], $reply['result']);
    }

    public function testFailsFastWhenTheServerInitiatesARequestOverTheSseStream(): void
    {
        Http::fake(['*' => Http::response(sseStream([
            ['jsonrpc' => '2.0', 'id' => 99, 'method' => 'ping'],
        ]), 200, ['Content-Type' => 'text/event-stream'])]);

        $transport = new HttpTransport('https://mcp.test/mcp');

        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => []]));

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('does not support');
        $transport->receive();
    }

    public function testSkipsSseCommentsAndEmptyDataLines(): void
    {
        $stream = ": ping\n\ndata:\n\n" . sseStream([
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]],
        ]);

        Http::fake(['*' => Http::response($stream, 200, ['Content-Type' => 'text/event-stream'])]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => []]));

        $reply = json_decode($transport->receive(), true);
        $this->assertSame(1, $reply['id']);
        $this->assertSame(['ok' => true], $reply['result']);
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('No message available');
        $transport->receive();
    }

    public function testQueuesNothingForA202AcceptedNotification(): void
    {
        Http::fake(['*' => Http::response('', 202)]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->send(json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('No message available');
        $transport->receive();
    }

    public function testQueuesNothingWhenA200ResponseHasAnEmptyBody(): void
    {
        Http::fake(['*' => Http::response('', 200, ['Content-Type' => 'application/json'])]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->send(json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('No message available');
        $transport->receive();
    }

    public function testCapturesTheMcpSessionIdAndResendsItOnLaterRequests(): void
    {
        Http::fake([
            '*' => fn (): PromiseInterface => Http::response(
                json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]),
                200,
                ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'session-abc'],
            ),
        ]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::V2025_11_25);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]));
        $transport->receive();
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping', 'params' => []]));

        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'initialize' && ! $request->hasHeader('MCP-Session-Id'));
        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'ping' && $request->hasHeader('MCP-Session-Id', 'session-abc'));
    }

    public function testKeepsTheSessionAcrossALegacyVersionChangeAndDropsItOnAnEraSwitch(): void
    {
        Http::fake([
            '*' => fn (): PromiseInterface => Http::response(
                json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]),
                200,
                ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'session-abc'],
            ),
        ]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::V2025_11_25);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]));
        $transport->receive();
        $transport->useProtocol(ProtocolVersion::V2025_06_18);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping', 'params' => []]));
        $transport->receive();
        $transport->useProtocol(ProtocolVersion::LATEST);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => []]));

        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'ping' && $request->hasHeader('MCP-Session-Id', 'session-abc'));
        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'tools/list' && ! $request->hasHeader('MCP-Session-Id'));
    }

    public function testIgnoresSessionIdsReturnedByDiscoveryProtocols(): void
    {
        Http::fake(['*' => Http::response(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]),
            200,
            ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'stale-session'],
        )]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::LATEST);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => []]));
        $transport->disconnect();

        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
    }

    public function testOmitsTheProtocolVersionHeaderOnInitializeAndIncludesTheNegotiatedVersionAfterwards(): void
    {
        Http::fake(['*' => fn (): PromiseInterface => Http::response(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]), 200, ['Content-Type' => 'application/json'])]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::V2025_11_25);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]));
        $transport->receive();
        $transport->useProtocol(ProtocolVersion::V2025_06_18);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']));

        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'initialize' && ! $request->hasHeader('MCP-Protocol-Version'));
        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'tools/list' && $request->hasHeader('MCP-Protocol-Version', ProtocolVersion::V2025_06_18->value));
    }

    public function testSendsABearerAuthorizationHeaderWhenATokenIsSet(): void
    {
        Http::fake(['*' => Http::response(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]), 200, ['Content-Type' => 'application/json'])]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->withToken('secret-token');
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => []]));

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer secret-token'));
    }

    public function testSendsCustomHeadersAlongsideTheDefaultHeaders(): void
    {
        Http::fake(['*' => Http::response(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]), 200, ['Content-Type' => 'application/json'])]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->withHeaders(['X-Tenant-Id' => 'acme', 'X-Api-Version' => '2025-01-01']);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => []]));

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Tenant-Id', 'acme')
            && $request->hasHeader('X-Api-Version', '2025-01-01')
            && $request->hasHeader('Accept', 'application/json, text/event-stream'));
    }

    public function testReplacesADefaultHeaderCaseInsensitivelyInsteadOfSendingADuplicate(): void
    {
        Http::fake(['*' => Http::response(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]), 200, ['Content-Type' => 'application/json'])]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->withToken('secret-token');
        $transport->withHeaders(['accept' => 'text/plain', 'authorization' => 'raw-api-key']);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => []]));

        Http::assertSent(function ($request): bool {
            $headers = [];

            foreach ($request->headers() as $name => $values) {
                $headers[strtolower($name)][] = implode(',', $values);
            }

            return $headers['accept'] === ['text/plain']
                && $headers['authorization'] === ['raw-api-key'];
        });
    }

    public function testLetsACustomAuthorizationHeaderOverrideTheBearerToken(): void
    {
        Http::fake(['*' => Http::response(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]), 200, ['Content-Type' => 'application/json'])]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->withToken('secret-token');
        $transport->withHeaders(['Authorization' => 'raw-api-key']);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => []]));

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'raw-api-key'));
    }

    public function testMergesCustomHeadersAcrossMultipleWithHeadersCalls(): void
    {
        Http::fake(['*' => Http::response(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]), 200, ['Content-Type' => 'application/json'])]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->withHeaders(['X-One' => 'first']);
        $transport->withHeaders(['X-Two' => 'second', 'X-One' => 'overridden']);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => []]));

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-One', 'overridden') && $request->hasHeader('X-Two', 'second'));
    }

    public function testSendsCustomHeadersWhenBuiltViaClientWeb(): void
    {
        legacyEndpoint()
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                    'capabilities' => new stdClass,
                    'serverInfo' => ['name' => 'Test Server', 'version' => '1.0.0'],
                ],
            ]), 200, ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'session-headers'])
            ->push('', 202)
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'result' => ['tools' => [['name' => 'add', 'description' => 'Adds two numbers']]],
            ]), 200, ['Content-Type' => 'application/json'])
            ->whenEmpty(Http::response('', 202));

        $client = Client::web('https://mcp.test/mcp')->withHeaders(['Authorization' => 'raw-api-key', 'X-Tenant-Id' => 'acme']);

        $this->assertInstanceOf(WebClient::class, $client);

        $client->tools();

        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'tools/list'
            && $request->hasHeader('Authorization', 'raw-api-key')
            && $request->hasHeader('X-Tenant-Id', 'acme'));
    }

    public function testThrowsAClientExceptionAndResetsTheSessionOnA404Response(): void
    {
        Http::fakeSequence()
            ->push(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]), 200, ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'session-xyz'])
            ->push('', 404);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::V2025_11_25);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]));
        $transport->receive();

        $this->expectException(SessionExpiredException::class);
        $this->expectExceptionMessage('Session expired');
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping', 'params' => []]));
    }

    public function testTreatsA404WithoutASessionIdAsANormalErrorRatherThanSessionExpiry(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $transport = new HttpTransport('https://mcp.test/mcp');

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Unexpected HTTP status [404]');
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => []]));
    }

    public function testThrowsAClientExceptionOnAnUnexpectedHttpStatus(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $transport = new HttpTransport('https://mcp.test/mcp');

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Unexpected HTTP status [500]');
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => []]));
    }

    public function testSendsADeleteWithTheSessionIdOnDisconnect(): void
    {
        Http::fake(['*' => Http::response(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]),
            200,
            ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'session-del'],
        )]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::V2025_11_25);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]));
        $transport->receive();
        $transport->disconnect();

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && $request->hasHeader('MCP-Session-Id', 'session-del'));
    }

    public function testIncludesTheAuthorizationHeaderOnTheTerminatingDelete(): void
    {
        Http::fake(['*' => Http::response(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]),
            200,
            ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'session-auth'],
        )]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->withToken('del-token');
        $transport->useProtocol(ProtocolVersion::V2025_11_25);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]));
        $transport->receive();
        $transport->disconnect();

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && $request->hasHeader('Authorization', 'Bearer del-token') && $request->hasHeader('MCP-Session-Id', 'session-auth'));
    }

    public function testDoesNotSendADeleteWhenThereIsNoActiveSession(): void
    {
        Http::fake();

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->disconnect();

        Http::assertNothingSent();
    }

    public function testSwallowsErrorsRaisedWhileTerminatingTheSessionOnDisconnect(): void
    {
        Http::fake(function ($request): PromiseInterface {
            if ($request->method() === 'DELETE') {
                throw new ConnectionException('connection lost');
            }

            return Http::response(
                json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]),
                200,
                ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'session-boom'],
            );
        });

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::V2025_11_25);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]));
        $transport->receive();
        $transport->disconnect();

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('No message available');
        $transport->receive();
    }

    public function testMapsAConnectionFailureToAClientException(): void
    {
        Http::fake(function (Request $request): PromiseInterface {
            if (($request['method'] ?? null) === 'initialize') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], headers: ['MCP-Session-Id' => 'failed-session']);
            }

            throw new ConnectionException('timed out');
        });

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::V2025_11_25);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']));
        $transport->receive();

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('HTTP request to [https://mcp.test/mcp] failed');
        try {
            $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping', 'params' => []]));
        } finally {
            $transport->disconnect();
            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        }
    }

    public function testThrowsWhenReceivingWithNoQueuedMessage(): void
    {
        $transport = new HttpTransport('https://mcp.test/mcp');

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('No message available');
        $transport->receive();
    }

    public function testDrivesAFullHandshakeAndToolsListOverHttpViaClientWeb(): void
    {
        legacyEndpoint()
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                    'capabilities' => new stdClass,
                    'serverInfo' => ['name' => 'Test Server', 'version' => '1.0.0'],
                ],
            ]), 200, ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'session-e2e'])
            ->push('', 202)
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'result' => ['tools' => [['name' => 'add', 'description' => 'Adds two numbers']]],
            ]), 200, ['Content-Type' => 'application/json'])
            ->whenEmpty(Http::response('', 202));

        $tools = Client::web('https://mcp.test/mcp')
            ->withToken('e2e-token')
            ->withTimeout(15)
            ->tools();

        $this->assertSame(['add'], $tools->keys()->all());

        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'tools/list' && $request->hasHeader('MCP-Session-Id', 'session-e2e'));
        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'tools/list' && $request->hasHeader('Authorization', 'Bearer e2e-token'));
        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'tools/list' && $request->hasHeader('MCP-Protocol-Version', ProtocolVersion::V2025_11_25->value));
    }

    public function testThrowsWhenTheServerNegotiatesAProtocolVersionTheClientDoesNotSupport(): void
    {
        legacyEndpoint()
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'protocolVersion' => ProtocolVersion::V2025_03_26->value,
                    'capabilities' => new stdClass,
                    'serverInfo' => ['name' => 'Test Server', 'version' => '1.0.0'],
                ],
            ]), 200, ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'session-old'])
            ->whenEmpty(Http::response('', 202));

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('The server chose protocol version [2025-03-26]. This client supports [2025-11-25, 2025-06-18].');
        Client::web('https://mcp.test/mcp')->tools();
    }

    public function testInteroperatesWithAServerNegotiatedDownTo20250618(): void
    {
        legacyEndpoint()
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'protocolVersion' => ProtocolVersion::V2025_06_18->value,
                    'capabilities' => new stdClass,
                    'serverInfo' => ['name' => 'Legacy Server', 'version' => '1.0.0'],
                ],
            ]), 200, ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'session-2025-06-18'])
            ->push('', 202)
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'result' => [
                    'tools' => [[
                        'name' => 'add',
                        'title' => 'Add Numbers',
                        'description' => 'Adds two numbers',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => ['a' => ['type' => 'number'], 'b' => ['type' => 'number']],
                            'required' => ['a', 'b'],
                        ],
                        'outputSchema' => [
                            'type' => 'object',
                            'properties' => ['sum' => ['type' => 'number']],
                        ],
                    ]],
                ],
            ]), 200, ['Content-Type' => 'application/json'])
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 4,
                'result' => [
                    'content' => [['type' => 'text', 'text' => '3']],
                    'structuredContent' => ['sum' => 3],
                    'isError' => false,
                ],
            ]), 200, ['Content-Type' => 'application/json'])
            ->whenEmpty(Http::response('', 202));

        $client = Client::web('https://mcp.test/mcp');
        $tools = $client->tools();
        $result = $client->callTool('add', ['a' => 1, 'b' => 2]);

        $this->assertSame(['add'], $tools->keys()->all());
        $this->assertSame('Add Numbers', $tools->get('add')->title);
        $this->assertSame([
            'type' => 'object',
            'properties' => ['sum' => ['type' => 'number']],
        ], $tools->get('add')->outputSchema);
        $this->assertSame('3', $result->text());
        $this->assertSame(['sum' => 3], $result->structuredContent);
        $this->assertFalse($result->isError);

        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'initialize' && ! $request->hasHeader('MCP-Protocol-Version'));
        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'notifications/initialized' && $request->hasHeader('MCP-Protocol-Version', ProtocolVersion::V2025_06_18->value));
        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'tools/list' && $request->hasHeader('MCP-Protocol-Version', ProtocolVersion::V2025_06_18->value));
        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'tools/call' && $request->hasHeader('MCP-Protocol-Version', ProtocolVersion::V2025_06_18->value));
    }

    public function testBuildsAWebClientFromClientWeb(): void
    {
        $this->assertInstanceOf(WebClient::class, Client::web('https://mcp.test/mcp'));
    }

    public function testReinitializesAndRetriesOnceAfterA404SessionExpiry(): void
    {
        legacyEndpoint()
            ->push(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new stdClass,
                'serverInfo' => ['name' => 'Test Server', 'version' => '1.0.0'],
            ]]), 200, ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'session-1'])
            ->push('', 202)
            ->push('', 404)
            ->push(json_encode(['jsonrpc' => '2.0', 'id' => 4, 'result' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new stdClass,
                'serverInfo' => ['name' => 'Test Server', 'version' => '1.0.0'],
            ]]), 200, ['Content-Type' => 'application/json', 'MCP-Session-Id' => 'session-2'])
            ->push('', 202)
            ->push(json_encode(['jsonrpc' => '2.0', 'id' => 5, 'result' => [
                'tools' => [['name' => 'add', 'description' => 'Adds two numbers']],
            ]]), 200, ['Content-Type' => 'application/json'])
            ->whenEmpty(Http::response('', 202));

        $tools = Client::web('https://mcp.test/mcp')->tools();

        $this->assertSame(['add'], $tools->keys()->all());

        Http::assertSent(fn ($request): bool => ($request['method'] ?? null) === 'tools/list' && $request->hasHeader('MCP-Session-Id', 'session-2'));
    }

    public function testSurfacesA404DuringTheInitializeHandshakeAsANormalError(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Unexpected HTTP status [404]');
        Client::web('https://mcp.test/mcp')->connect();
    }

    public function testSendsTheStandardAndMirroredParameterHeadersOnARealRequest(): void
    {
        Http::fake(['*' => Http::response(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['resultType' => 'complete', 'content' => []]]),
            200,
            ['Content-Type' => 'application/json'],
        )]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::LATEST);
        $transport->send(
            (string) json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'execute_sql', 'arguments' => ['region' => 'us-west1']],
            ]),
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'execute_sql', 'Mcp-Param-Region' => 'us-west1'],
        );

        Http::assertSent(fn ($request): bool => $request->hasHeaders([
            'MCP-Protocol-Version' => ProtocolVersion::LATEST->value,
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'execute_sql',
            'Mcp-Param-Region' => 'us-west1',
        ]));
    }

    public function testNeverLetsCustomHeadersOverrideTheMcpOwnedHeaders(): void
    {
        Http::fake(['*' => Http::response('', 202)]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::LATEST);
        $transport->withHeaders(['mcp-param-region' => 'us-east4', 'Mcp-Name' => 'other', 'X-Tenant-Id' => 'acme']);
        $transport->send(
            (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => []]),
            ['Mcp-Name' => 'execute_sql', 'Mcp-Param-Region' => 'us-west1'],
        );

        Http::assertSent(fn ($request): bool => $request->hasHeaders([
            'Mcp-Name' => 'execute_sql',
            'Mcp-Param-Region' => 'us-west1',
            'X-Tenant-Id' => 'acme',
        ]));
    }

    #[TestWith(["\n"])]
    #[TestWith(["\r\n"])]
    public function testJoinsSseDataLinesAtEventBoundaries(string $newline): void
    {
        $body = implode($newline, [
            ': keepalive',
            'event: message',
            'id: ignored',
            'data: {"jsonrpc":"2.0",',
            'data: "id":1,"result":{"ok":true}}',
            '',
            '',
        ]);
        Http::fake(['*' => Http::response($body, headers: ['Content-Type' => 'text/event-stream'])]);
        $transport = new HttpTransport('https://mcp.test/mcp');

        try {
            $transport->send('{"jsonrpc":"2.0","id":1,"method":"ping"}');
            $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]], json_decode($transport->receive(), true));
        } finally {
            $transport->disconnect();
        }
    }

    public function testCancelledSessionTerminationStillClosesTheResponseAndForgetsTheSession(): void
    {
        $exception = new CanceledException('DELETE cancelled');
        $body = Utils::streamFor('{"jsonrpc":"2.0","id":1,"result":{}}');
        $deletes = 0;
        Http::fake(function (Request $request) use ($body, $exception, &$deletes): PromiseInterface {
            if ($request->method() === 'DELETE') {
                ++$deletes;
                throw $exception;
            }

            return Http::response($body, headers: ['MCP-Session-Id' => 'owned-session']);
        });
        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::V2025_11_25);
        $transport->send('{"jsonrpc":"2.0","id":1,"method":"initialize"}');

        try {
            $transport->disconnect();
            $this->fail('Session termination should propagate cancellation.');
        } catch (CanceledException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertFalse($body->isReadable());
        $transport->disconnect();
        $this->assertSame(1, $deletes);
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('No message available');
        $transport->receive();
    }

    #[TestWith([200])]
    #[TestWith([404])]
    public function testAnOlderResponseCannotReplaceOrResetANewerSession(int $status): void
    {
        $started = new Channel(1);
        $resume = new Channel(1);
        Http::fake(function (Request $request) use ($started, $resume, $status): PromiseInterface {
            if (($request['method'] ?? null) === 'old') {
                $this->assertTrue($started->push(true, 1));
                $this->assertTrue($resume->pop(1));

                return Http::response(['jsonrpc' => '2.0', 'id' => 2, 'result' => []], $status, ['MCP-Session-Id' => 'stale-session']);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'] ?? 0, 'result' => []], headers: ['MCP-Session-Id' => 'current-session']);
        });
        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::V2025_11_25);
        $transport->send('{"jsonrpc":"2.0","id":1,"method":"initialize"}');
        $transport->endExchange();

        try {
            parallel([
                function () use ($transport, $status): void {
                    try {
                        $transport->send('{"jsonrpc":"2.0","id":2,"method":"old"}');
                        $this->assertSame(200, $status);
                        $this->assertSame(2, json_decode($transport->receive(), true)['id']);
                    } catch (SessionExpiredException $exception) {
                        $this->assertSame(404, $status);
                    } finally {
                        $transport->endExchange();
                    }
                },
                function () use ($transport, $started, $resume): void {
                    $this->assertTrue($started->pop(1));
                    try {
                        $transport->connect();
                        $transport->useProtocol(ProtocolVersion::V2025_11_25);
                        $transport->send('{"jsonrpc":"2.0","id":3,"method":"initialize"}');
                        $this->assertSame(3, json_decode($transport->receive(), true)['id']);
                    } finally {
                        $transport->endExchange();
                        $resume->push(true, 1);
                    }
                },
            ]);

            $transport->send('{"jsonrpc":"2.0","id":4,"method":"next"}');
            Http::assertSent(fn (Request $request): bool => ($request['method'] ?? null) === 'next'
                && $request->hasHeader('MCP-Session-Id', 'current-session')
                && $request->hasHeader('MCP-Protocol-Version', ProtocolVersion::V2025_11_25->value));
        } finally {
            $transport->disconnect();
            $started->close();
            $resume->close();
        }
    }

    public function testDisconnectClosesAResponseThatArrivesAfterItsExchangeWasInvalidated(): void
    {
        $started = new Channel(1);
        $resume = new Channel(1);
        $body = Utils::streamFor('{"jsonrpc":"2.0","id":1,"result":{}}');
        Http::fake(function () use ($started, $resume, $body): PromiseInterface {
            $this->assertTrue($started->push(true, 1));
            $this->assertTrue($resume->pop(1));

            return Http::response($body, headers: ['MCP-Session-Id' => 'late-session']);
        });
        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->useProtocol(ProtocolVersion::V2025_11_25);

        try {
            parallel([
                function () use ($transport): void {
                    try {
                        $transport->send('{"jsonrpc":"2.0","id":1,"method":"initialize"}');
                        $this->fail('A disconnected exchange must reject its late response.');
                    } catch (ClientException $exception) {
                        $this->assertStringContainsString('exchange was closed', $exception->getMessage());
                    }
                },
                function () use ($transport, $started, $resume): void {
                    $this->assertTrue($started->pop(1));
                    try {
                        $transport->disconnect();
                    } finally {
                        $resume->push(true, 1);
                    }
                },
            ]);

            $this->assertFalse($body->isReadable());
            $transport->disconnect();
            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        } finally {
            $transport->disconnect();
            $started->close();
            $resume->close();
        }
    }

    public function testDisconnectLeavesAPausedResponseBodyForItsReaderToClose(): void
    {
        $started = new Channel(1);
        $resume = new Channel(1);
        $beforeRead = function () use ($started, $resume): void {
            $this->assertTrue($started->push(true, 1));
            $this->assertTrue($resume->pop(1));
        };
        $body = new class(Utils::streamFor("data: {}\n\n"), $beforeRead) implements StreamInterface {
            use StreamDecoratorTrait;

            public ?int $closedBy = null;

            /**
             * Pause the first read of the wrapped response.
             */
            public function __construct(private StreamInterface $stream, private Closure $beforeRead)
            {
            }

            /**
             * Read bytes after the caller permits progress.
             */
            public function read(int $length): string
            {
                if ($this->stream->tell() === 0) {
                    ($this->beforeRead)();
                }

                return $this->stream->read($length);
            }

            /**
             * Record which coroutine releases the body.
             */
            public function close(): void
            {
                $this->closedBy = Coroutine::id();
                $this->stream->close();
            }
        };
        Http::fake(['*' => Http::response($body, headers: ['Content-Type' => 'text/event-stream'])]);
        $transport = new HttpTransport('https://mcp.test/mcp');
        $reader = null;

        try {
            parallel([
                function () use ($transport, &$reader): void {
                    $reader = Coroutine::id();

                    try {
                        $transport->send('{"jsonrpc":"2.0","id":1,"method":"ping"}');
                        $transport->receive();
                        $this->fail('The disconnected exchange must not deliver a reply.');
                    } catch (ClientException $exception) {
                        $this->assertStringContainsString('exchange was closed', $exception->getMessage());
                    } finally {
                        $transport->endExchange();
                    }
                },
                function () use ($transport, $body, $started, $resume): void {
                    $this->assertTrue($started->pop(1));

                    try {
                        $transport->disconnect();
                        $this->assertTrue($body->isReadable());
                        $this->assertNull($body->closedBy);
                    } finally {
                        $resume->push(true, 1);
                    }
                },
            ]);

            $this->assertSame($reader, $body->closedBy);
            $this->assertFalse($body->isReadable());
        } finally {
            $transport->disconnect();
            $started->close();
            $resume->close();
        }
    }

    public function testDisconnectRejectsASiblingsAlreadyBufferedReply(): void
    {
        $queued = new Channel(1);
        $resume = new Channel(1);
        $body = Utils::streamFor('{"jsonrpc":"2.0","id":1,"result":{}}');
        Http::fake(['*' => Http::response($body)]);
        $transport = new HttpTransport('https://mcp.test/mcp');

        try {
            parallel([
                function () use ($transport, $queued, $resume): void {
                    try {
                        $transport->send('{"jsonrpc":"2.0","id":1,"method":"ping"}');
                        $this->assertTrue($queued->push(true, 1));
                        $this->assertTrue($resume->pop(1));
                        $transport->receive();
                        $this->fail('The buffered reply belongs to a disconnected exchange.');
                    } catch (ClientException $exception) {
                        $this->assertStringContainsString('exchange was closed', $exception->getMessage());
                    } finally {
                        $transport->endExchange();
                    }
                },
                function () use ($transport, $queued, $resume): void {
                    $this->assertTrue($queued->pop(1));

                    try {
                        $transport->disconnect();
                    } finally {
                        $resume->push(true, 1);
                    }
                },
            ]);

            $this->assertFalse($body->isReadable());
        } finally {
            $transport->disconnect();
            $queued->close();
            $resume->close();
        }
    }

    public function testConcurrentSessionExpirySharesOneReplacementNegotiation(): void
    {
        $bothSent = new Channel(1);
        $oldRequests = 0;
        $initializations = 0;
        Http::fake(function (Request $request) use ($bothSent, &$oldRequests, &$initializations): PromiseInterface {
            if (($request['method'] ?? null) === 'initialize') {
                ++$initializations;

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                        'capabilities' => new stdClass,
                        'serverInfo' => ['name' => 'Session fixture', 'version' => '1.0'],
                    ],
                ], headers: ['MCP-Session-Id' => 'session-' . $initializations]);
            }

            if (($request['method'] ?? null) !== 'tools/call') {
                return Http::response('', 202);
            }

            if ($request->hasHeader('MCP-Session-Id', 'session-1')) {
                if (++$oldRequests === 1) {
                    $this->assertTrue($bothSent->pop(1));
                } else {
                    $this->assertTrue($bothSent->push(true, 1));
                }

                return Http::response('', 404);
            }

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => $request['id'],
                'result' => ['content' => [['type' => 'text', 'text' => $request['params']['name']]]],
            ]);
        });
        $client = Client::web('https://mcp.test/mcp')->withProtocolVersion(ProtocolVersion::V2025_11_25);

        try {
            $results = parallel([
                fn (): string => $client->callTool('first')->text(),
                fn (): string => $client->callTool('second')->text(),
            ]);

            $this->assertSame(['first', 'second'], $results);
            $this->assertSame(2, $oldRequests);
            $this->assertSame(2, $initializations);
            $this->assertTrue($client->connected());
        } finally {
            $client->disconnect();
            $bothSent->close();
        }
    }
}
