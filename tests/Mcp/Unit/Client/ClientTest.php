<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client;

use Hypervel\Mcp\Client;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\WebClient;
use Hypervel\Tests\Mcp\Fixtures\Client\FakeTransport;
use Hypervel\Tests\Mcp\TestCase;
use stdClass;

use function Hypervel\Tests\Mcp\Fixtures\initializeResponse;
use function Hypervel\Tests\Mcp\Fixtures\pingResponse;

class ClientTest extends TestCase
{
    public function testPerformsTheInitializeHandshakeOnConnect(): void
    {
        config(['app.name' => 'Acme MCP App']);

        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();

        $client = new Client($transport);
        $client->connect();

        $this->assertTrue($transport->connected);
        $this->assertTrue($client->connected());
        $this->assertSame(ProtocolVersion::V2025_11_25->value, $client->initializeResult()?->protocolVersion);
        $this->assertSame('Test Server', $client->initializeResult()?->serverInfo->name);
        $this->assertSame('1.0.0', $client->initializeResult()?->serverInfo->version);
        $this->assertIsArray($client->initializeResult()?->capabilities);
        $this->assertNotNull($client->initializeResult());
        $this->assertNull($client->initializeResult()?->instructions);

        $initialize = json_decode($transport->sent[0], true);
        $this->assertSame('initialize', $initialize['method']);
        $this->assertSame(ProtocolVersion::V2025_11_25->value, $initialize['params']['protocolVersion']);
        $this->assertSame('Acme MCP App', $initialize['params']['clientInfo']['name']);

        $initialized = json_decode($transport->sent[1], true);
        $this->assertSame('notifications/initialized', $initialized['method']);
        $this->assertArrayNotHasKey('id', $initialized);
    }

    public function testDisconnectsWhenTheServerNegotiatesAVersionTheClientDoesNotSupport(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'protocolVersion' => ProtocolVersion::V2024_11_05->value,
                'capabilities' => new stdClass,
                'serverInfo' => ['name' => 'Test Server', 'version' => '1.0.0'],
            ],
        ]);

        $client = new Client($transport);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('The server chose protocol version [2024-11-05]. This client supports [2025-11-25, 2025-06-18].');
        try {
            $client->connect();
        } finally {
            $this->assertFalse($transport->connected);
        }
    }

    public function testPingsTheServer(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = pingResponse(2);

        $client = new Client($transport);
        $client->ping();

        $ping = json_decode($transport->sent[2], true);
        $this->assertSame('ping', $ping['method']);
        $this->assertSame(3, $ping['id']);
    }

    public function testLazilyConnectsWhenPingIsCalledFirst(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = pingResponse(2);

        $client = new Client($transport);

        $this->assertFalse($client->connected());

        $client->ping();

        $this->assertTrue($client->connected());
    }

    public function testDoesNotReconnectWhenAlreadyConnected(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();

        $client = new Client($transport);
        $client->connect();
        $client->connect();

        $this->assertCount(2, $transport->sent);
    }

    public function testDisconnectsCleanly(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();

        $client = new Client($transport);
        $client->connect();
        $client->disconnect();

        $this->assertFalse($transport->connected);
        $this->assertFalse($client->connected());
    }

    public function testClearsConnectedStateWhenARequestFailsAfterTheHandshake(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = '{not json';

        $client = new Client($transport);
        $client->connect();

        $this->assertTrue($client->connected());

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Malformed JSON-RPC response from server');
        try {
            $client->ping();
        } finally {
            $this->assertFalse($client->connected());
            $this->assertFalse($transport->connected);
        }
    }

    public function testKeepsTheConnectionOpenAfterAJsonRpcErrorResponse(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'error' => ['code' => -32601, 'message' => 'Method not found'],
        ]);

        $client = new Client($transport);
        $client->connect();

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Method not found');
        try {
            $client->ping();
        } finally {
            $this->assertTrue($client->connected());
            $this->assertTrue($transport->connected);
        }
    }

    public function testSkipsNotificationFramesReceivedBeforeTheMatchingResponse(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/message',
            'params' => ['level' => 'info', 'data' => 'hello'],
        ]);
        $transport->responses[] = pingResponse(2);

        $client = new Client($transport);
        $client->ping();

        $this->assertEmpty($transport->responses);
    }

    public function testRethrowsJsonRpcErrorsAndDisconnectsTheTransportOnHandshakeFailure(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => -32600, 'message' => 'Invalid request'],
        ]);

        $client = new Client($transport);

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid request');
        try {
            $client->connect();
        } finally {
            $this->assertFalse($transport->connected);
            $this->assertFalse($client->connected());
        }
    }

    public function testThrowsWhenTheResponsePayloadIsMalformedJson(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = '{not json';

        $client = new Client($transport);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Malformed JSON-RPC response from server');
        $client->connect();
    }

    public function testThrowsWhenTheResponseIsMissingTheJsonRpcVersion(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = json_encode([
            'id' => 1,
            'result' => ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass, 'serverInfo' => ['name' => 'x', 'version' => 'y']],
        ]);

        $client = new Client($transport);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid JSON-RPC response from server.');
        $client->connect();
    }

    public function testThrowsWhenTheResponseCarriesBothResultAndError(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [],
            'error' => ['code' => -32600, 'message' => 'nope'],
        ]);

        $client = new Client($transport);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('must contain exactly one of "result" or "error"');
        $client->connect();
    }

    public function testThrowsWhenTheResponseCarriesNeitherResultNorError(): void
    {
        $transport = new FakeTransport;
        $transport->negotiates = true;
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
        ]);

        $client = new Client($transport);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('must contain exactly one of "result" or "error"');
        $client->connect();
    }

    public function testThrowsWhenTheErrorPayloadIsNotAnObject(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => 'not an object',
        ]);

        $client = new Client($transport);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid JSON-RPC error payload.');
        $client->connect();
    }

    public function testThrowsWhenTheInitializeResultIsStructurallyInvalid(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new stdClass,
                'serverInfo' => ['version' => '1.0.0'],
            ],
        ]);

        $client = new Client($transport);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid initialize response from server.');
        $client->connect();
    }

    public function testRespondsToServerInitiatedPingRequestsWithAnEmptyResult(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 'server-ping-1',
            'method' => 'ping',
        ]);
        $transport->responses[] = pingResponse(2);

        $client = new Client($transport);
        $client->ping();

        $pingReply = json_decode($transport->sent[3], true);
        $this->assertSame('server-ping-1', $pingReply['id']);
        $this->assertSame([], $pingReply['result']);
        $this->assertArrayNotHasKey('error', $pingReply);
    }

    public function testRespondsWithMethodNotFoundToUnsupportedServerInitiatedRequests(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 'sampling-1',
            'method' => 'sampling/createMessage',
            'params' => ['messages' => []],
        ]);
        $transport->responses[] = pingResponse(2);

        $client = new Client($transport);
        $client->ping();

        $errorReply = json_decode($transport->sent[3], true);
        $this->assertSame('sampling-1', $errorReply['id']);
        $this->assertSame(-32601, $errorReply['error']['code']);
        $this->assertStringContainsString('sampling/createMessage', $errorReply['error']['message']);
        $this->assertArrayNotHasKey('result', $errorReply);
    }

    public function testStoresTheFullServerInfoAndInstructionsFromInitialize(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new stdClass,
                'serverInfo' => [
                    'name' => 'ExampleServer',
                    'title' => 'Example Server Display Name',
                    'version' => '1.0.0',
                    'description' => 'An example MCP server providing tools and resources',
                    'icons' => [['src' => 'https://example.com/server-icon.svg', 'mimeType' => 'image/svg+xml']],
                    'websiteUrl' => 'https://example.com/server',
                ],
                'instructions' => 'Optional instructions for the client',
            ],
        ]);

        $client = new Client($transport);
        $client->connect();

        $result = $client->initializeResult();
        $this->assertNotNull($result);
        $info = $result->serverInfo;
        $this->assertSame('ExampleServer', $info->name);
        $this->assertSame('Example Server Display Name', $info->title);
        $this->assertSame('1.0.0', $info->version);
        $this->assertSame('An example MCP server providing tools and resources', $info->description);
        $this->assertCount(1, $info->icons);
        $this->assertSame('https://example.com/server-icon.svg', $info->icons[0]->src);
        $this->assertSame('image/svg+xml', $info->icons[0]->mimeType);
        $this->assertSame([], $info->icons[0]->sizes);
        $this->assertNull($info->icons[0]->theme);
        $this->assertSame('https://example.com/server', $info->websiteUrl);
        $this->assertSame('Optional instructions for the client', $result->instructions);
    }

    public function testParsesIconsWithSizesAndThemeWhenTheServerIncludesThem(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new stdClass,
                'serverInfo' => [
                    'name' => 'ExampleServer',
                    'version' => '1.0.0',
                    'icons' => [[
                        'src' => 'https://example.com/icon.svg',
                        'mimeType' => 'image/svg+xml',
                        'sizes' => ['48x48', '96x96'],
                        'theme' => 'dark',
                    ]],
                ],
            ],
        ]);

        $client = new Client($transport);
        $client->connect();

        $icon = $client->initializeResult()?->serverInfo->icons[0] ?? null;
        $this->assertNotNull($icon);
        $this->assertSame(['48x48', '96x96'], $icon->sizes);
        $this->assertSame('dark', $icon->theme?->value);
    }

    public function testTimesOutWhenAStdioProcessStaysSilent(): void
    {
        $client = Client::local(PHP_BINARY, ['-r', 'sleep(1);'])->withTimeout(0.05);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Timed out while waiting for server response.');
        try {
            $client->connect();
        } finally {
            $client->disconnect();
        }
    }

    public function testPreservesItsShapeAcrossASerializeRoundTripAndWakesDisconnected(): void
    {
        config(['app.name' => 'Acme MCP App']);

        $client = Client::local('node', ['server.js'])->withTimeout(12.5);

        $payload = serialize($client);
        $restored = unserialize($payload);

        $this->assertInstanceOf(Client::class, $restored);
        $this->assertFalse($restored->connected());
        $this->assertEquals($client->__serialize(), $restored->__serialize());
        $this->assertStringContainsString('node', $payload);
        $this->assertStringNotContainsString('Symfony\Component\Process', $payload);
    }

    public function testRestoresThePinnedProtocolVersionAcrossASerializeRoundTrip(): void
    {
        $client = Client::web('https://mcp.test/mcp')->withProtocolVersion(ProtocolVersion::V2025_11_25);

        $restored = unserialize(serialize($client));

        $this->assertSame(ProtocolVersion::V2025_11_25->value, $restored->__serialize()['protocolVersion']);
    }

    public function testRestoresAWebClientTransportAcrossASerializeRoundTrip(): void
    {
        $client = Client::web('https://mcp.test/mcp')->withToken('tok');

        $restored = unserialize(serialize($client));

        $this->assertInstanceOf(WebClient::class, $restored);
        $this->assertFalse($restored->connected());
        $this->assertSame($restored, $restored->withToken('rotated'));
    }
}
