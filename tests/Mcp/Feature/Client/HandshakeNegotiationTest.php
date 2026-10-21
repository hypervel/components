<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Client;

use Hypervel\Http\Client\Request;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Enums\RequestHeader;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Mcp\TestCase;

use function Hypervel\Tests\Mcp\Fixtures\discoverResponse;
use function Hypervel\Tests\Mcp\Fixtures\initializeResponse;

class HandshakeNegotiationTest extends TestCase
{
    public function testSendsTheProtocolVersionWithoutASessionOnADiscoveryEraCall(): void
    {
        Http::fakeSequence()
            ->push(discoverResponse(), 200, ['Content-Type' => 'application/json'])
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => ['content' => [['type' => 'text', 'text' => 'hi']], 'isError' => false],
            ]), 200, ['Content-Type' => 'application/json']);

        Client::web('https://mcp.test/mcp')->callTool('say-hi', ['name' => 'John']);

        Http::assertSentInOrder([
            function (Request $request): bool {
                $this->assertSame('2026-07-28', $request->header(RequestHeader::PROTOCOL_VERSION->value)[0]);
                $this->assertFalse($request->hasHeader('MCP-Session-Id'));

                return true;
            },
            function (Request $request): bool {
                $this->assertSame('2026-07-28', $request->header(RequestHeader::PROTOCOL_VERSION->value)[0]);
                $this->assertFalse($request->hasHeader('MCP-Session-Id'));

                return true;
            },
        ]);
    }

    public function testReadsAProtocolErrorOutOfA400Response(): void
    {
        Http::fake([
            'https://mcp.test/mcp' => Http::response(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32022,
                    'message' => 'Unsupported protocol version',
                    'data' => ['supported' => ['2027-01-01'], 'requested' => '2026-07-28'],
                ],
            ]), 400, ['Content-Type' => 'application/json']),
        ]);

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Unsupported protocol version');
        try {
            Client::web('https://mcp.test/mcp')->withProtocolVersion(ProtocolVersion::LATEST)->tools()->all();
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function testRetriesWithAMutualVersionFromAProtocolErrorInA400Response(): void
    {
        Http::fakeSequence()
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32022,
                    'message' => 'Unsupported protocol version',
                    'data' => ['supported' => ['2025-11-25'], 'requested' => '2026-07-28'],
                ],
            ]), 400, ['Content-Type' => 'application/json'])
            ->push(initializeResponse(2), 200, ['Content-Type' => 'application/json'])
            ->push('', 202)
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'result' => ['tools' => []],
            ]), 200, ['Content-Type' => 'application/json']);

        $client = Client::web('https://mcp.test/mcp');

        $this->assertSame([], $client->tools()->all());
        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
    }

    public function testFallsBackToTheHandshakeWhenTheEndpointRejectsAModernRequest(): void
    {
        Http::fakeSequence()
            ->push('Method Not Allowed', 405)
            ->push(initializeResponse(2), 200, ['Content-Type' => 'application/json'])
            ->push('', 202)
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'result' => ['tools' => [['name' => 'add', 'description' => 'Adds']]],
            ]), 200, ['Content-Type' => 'application/json']);

        $client = Client::web('https://mcp.test/mcp');

        $this->assertSame(['add'], $client->tools()->keys()->all());
        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
    }

    public function testFallsBackToTheHandshakeWhenAGatewayRejectsTheProbeWithANonJsonRpcErrorBody(): void
    {
        Http::fakeSequence()
            ->push(json_encode(['error' => ['message' => 'invalid request']]), 400, ['Content-Type' => 'application/json'])
            ->push(initializeResponse(2), 200, ['Content-Type' => 'application/json'])
            ->push('', 202)
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'result' => ['tools' => []],
            ]), 200, ['Content-Type' => 'application/json']);

        $client = Client::web('https://mcp.test/mcp');

        $this->assertSame([], $client->tools()->all());
        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
    }

    public function testKeepsAnAuthorizationFailureIntactWhenTheLegacyHandshakeNeedsAToken(): void
    {
        Http::fakeSequence()
            ->push('Method Not Allowed', 405)
            ->push('Unauthorized', 401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.test/.well-known/oauth-protected-resource"']);

        $this->expectException(AuthorizationRequiredException::class);
        Client::web('https://mcp.test/mcp')->tools()->all();
    }

    public function testReportsTheRejectedStatusAlongsideAFailedFallback(): void
    {
        Http::fakeSequence()
            ->push('Method Not Allowed', 405)
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'error' => ['code' => -32603, 'message' => 'Internal error'],
            ]), 200, ['Content-Type' => 'application/json']);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('The endpoint [https://mcp.test/mcp] rejected the request with HTTP status [405]. The legacy handshake also failed: Internal error');
        Client::web('https://mcp.test/mcp')->tools()->all();
    }

    public function testDoesNotFallBackWhenTheEndpointIsUnavailable(): void
    {
        Http::fake([
            'https://mcp.test/mcp' => Http::response('Bad Gateway', 502),
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Unexpected HTTP status [502] from endpoint [https://mcp.test/mcp].');
        try {
            Client::web('https://mcp.test/mcp')->tools()->all();
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function testDoesNotSendAProtocolVersionHeaderThroughTheHandshake(): void
    {
        Http::fakeSequence()
            ->push('Bad Request', 400)
            ->push(initializeResponse(2), 200, ['Content-Type' => 'application/json'])
            ->push('', 202)
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'result' => ['tools' => []],
            ]), 200, ['Content-Type' => 'application/json']);

        Client::web('https://mcp.test/mcp')->tools();

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            if (($body['method'] ?? null) !== 'initialize') {
                return false;
            }

            return $request->header(RequestHeader::PROTOCOL_VERSION->value) === [];
        });
    }

    public function testFallsBackToTheHandshakeForAnyClientErrorTheProbeIsRefusedWith(): void
    {
        Http::fakeSequence()
            ->push('Conflict', 409)
            ->push(initializeResponse(2), 200, ['Content-Type' => 'application/json'])
            ->push('', 202)
            ->push(json_encode(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['tools' => []]]), 200, ['Content-Type' => 'application/json']);

        $client = Client::web('https://mcp.test/mcp');

        $this->assertSame([], $client->tools()->all());
        $this->assertSame(ProtocolVersion::V2025_11_25, $client->protocolVersion());
    }
}
