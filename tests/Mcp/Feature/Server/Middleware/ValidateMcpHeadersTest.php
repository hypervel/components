<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Server\Middleware;

use Hypervel\Mcp\Server\Methods\ListTools;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function Hypervel\Tests\Mcp\Fixtures\callToolMessage;
use function Hypervel\Tests\Mcp\Fixtures\initializeMessage;
use function Hypervel\Tests\Mcp\Fixtures\listToolsMessage;
use function Hypervel\Tests\Mcp\Fixtures\mcpHeaders;
use function Hypervel\Tests\Mcp\Fixtures\protocolMeta;

class ValidateMcpHeadersTest extends TestCase
{
    public function testAcceptsARequestWhoseHeadersMirrorTheBody(): void
    {
        $response = $this->postJson('test-mcp', $message = callToolMessage(), mcpHeaders($message));

        $response->assertStatus(200);
    }

    #[DataProvider('requiredHeaders')]
    public function testRejectsARequestMissingARequiredHeader(string $header): void
    {
        $message = callToolMessage();
        $headers = mcpHeaders($message);
        unset($headers[$header]);

        $response = $this->postJson('test-mcp', $message, $headers);

        $response->assertStatus(400);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'error' => [
                'code' => -32020,
                'message' => "Header mismatch: The [{$header}] header is required.",
            ],
        ], $response->json());
    }

    /**
     * Provide required modern request headers.
     */
    public static function requiredHeaders(): array
    {
        return [['MCP-Protocol-Version'], ['Mcp-Method'], ['Mcp-Name']];
    }

    public function testRejectsARequestWhoseHeaderContradictsTheBody(): void
    {
        $message = callToolMessage();

        $response = $this->postJson('test-mcp', $message, [
            ...mcpHeaders($message),
            'Mcp-Name' => 'some-other-tool',
        ]);

        $response->assertStatus(400);

        $this->assertEquals([
            'code' => -32020,
            'message' => "Header mismatch: The [Mcp-Name] header value [some-other-tool] does not match the request body value [{$message['params']['name']}].",
        ], $response->json('error'));
    }

    public function testRejectsAProtocolVersionHeaderThatContradictsTheBodyMeta(): void
    {
        $message = listToolsMessage();

        $response = $this->postJson('test-mcp', $message, [
            ...mcpHeaders($message),
            'MCP-Protocol-Version' => '2025-11-25',
        ]);

        $response->assertStatus(400);

        $this->assertSame(-32020, $response->json('error.code'));
    }

    #[DataProvider('requiredHeaders')]
    public function testIgnoresWhitespaceSurroundingAHeaderValue(string $header): void
    {
        $message = callToolMessage();
        $headers = mcpHeaders($message);

        $headers[$header] = " \t{$headers[$header]}  ";

        $response = $this->postJson('test-mcp', $message, $headers);

        $response->assertStatus(200);
    }

    public function testRejectsARequestWithoutMetadataThatSendsAStatelessProtocolVersionHeader(): void
    {
        $message = ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'server/discover', 'params' => []];

        $response = $this->postJson('test-mcp', $message, mcpHeaders($message));

        $response->assertStatus(400);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'id' => 3,
            'error' => [
                'code' => -32602,
                'message' => 'Invalid params: The request [_meta] is missing the required [io.modelcontextprotocol/protocolVersion] member.',
            ],
        ], $response->json());
    }

    #[DataProvider('removedLifecycleMethods')]
    public function testAnswersTheRemovedLifecycleMethodsWithA404OnAStatelessRequest(string $method): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => $method,
            'params' => ['_meta' => protocolMeta()],
        ];

        $response = $this->postJson('test-mcp', $message, mcpHeaders($message));

        $response->assertStatus(404);

        $this->assertEquals([
            'code' => -32601,
            'message' => "The method [{$method}] was not found.",
        ], $response->json('error'));
    }

    /**
     * Provide the legacy lifecycle methods removed from the stateless protocol.
     */
    public static function removedLifecycleMethods(): array
    {
        return [['initialize'], ['ping']];
    }

    #[DataProvider('invalidRequestIds')]
    public function testMalformedIdsAreRejectedByTheProtocolParser(mixed $id): void
    {
        $message = callToolMessage();
        $message['id'] = $id;

        $this->postJson('test-mcp', $message)
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32600)
            ->assertJsonPath('id', null);

        unset($message['params']['_meta']);

        $this->postJson('test-mcp', $message, ['MCP-Protocol-Version' => 'unsupported'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32600)
            ->assertJsonPath('id', null);
    }

    /**
     * Provide IDs that cannot be echoed in a protocol response.
     */
    public static function invalidRequestIds(): array
    {
        return [[true], [1.5], [['invalid']]];
    }

    public function testDecodesABase64SentinelNameHeaderBeforeComparingIt(): void
    {
        $message = callToolMessage();

        $response = $this->postJson('test-mcp', $message, [
            ...mcpHeaders($message),
            'Mcp-Name' => '=?base64?' . base64_encode((string) $message['params']['name']) . '?=',
        ]);

        $response->assertStatus(200);
    }

    public function testRejectsABase64NameHeaderThatDecodesToInvalidUtf8(): void
    {
        $message = callToolMessage();

        $response = $this->postJson('test-mcp', $message, [
            ...mcpHeaders($message),
            'Mcp-Name' => '=?base64?/w==?=',
        ]);

        $response->assertStatus(400);

        $this->assertEquals([
            'code' => -32020,
            'message' => "Header mismatch: The [Mcp-Name] header value [=?base64?/w==?=] does not match the request body value [{$message['params']['name']}].",
        ], $response->json('error'));
    }

    #[DataProvider('requiredHeaders')]
    public function testRejectsAHeaderCarryingInvalidUtf8Bytes(string $header): void
    {
        $message = callToolMessage();

        $response = $this->postJson('test-mcp', $message, [
            ...mcpHeaders($message),
            $header => "\xFF",
        ]);

        $response->assertStatus(400);

        $this->assertSame($message['id'], $response->json('id'));
        $this->assertSame(-32020, $response->json('error.code'));
        $this->assertStringContainsString('header value [=?base64?/w==?=]', $response->json('error.message'));
    }

    public function testQuotesANonAsciiNameHeaderDecodedInTheMismatchMessage(): void
    {
        $message = callToolMessage();

        $response = $this->postJson('test-mcp', $message, [
            ...mcpHeaders($message),
            'Mcp-Name' => '=?base64?SGVsbG8sIOS4lueVjA==?=',
        ]);

        $response->assertStatus(400);

        $this->assertSame("Header mismatch: The [Mcp-Name] header value [Hello, 世界] does not match the request body value [{$message['params']['name']}].", $response->json('error.message'));
    }

    public function testEncodesADecodedNameHeaderCarryingControlCharactersInTheMismatchMessage(): void
    {
        $message = callToolMessage();

        $response = $this->postJson('test-mcp', $message, [
            ...mcpHeaders($message),
            'Mcp-Name' => '=?base64?' . base64_encode("a\nb") . '?=',
        ]);

        $response->assertStatus(400);

        $this->assertSame('Header mismatch: The [Mcp-Name] header value [=?base64?' . base64_encode("a\nb") . "?=] does not match the request body value [{$message['params']['name']}].", $response->json('error.message'));
    }

    public function testDoesNotRequireANameHeaderForMethodsWithoutANamedTarget(): void
    {
        $message = listToolsMessage();

        $response = $this->postJson('test-mcp', $message, mcpHeaders($message));

        $response->assertStatus(200);

        $this->assertArrayNotHasKey('Mcp-Name', mcpHeaders($message));
    }

    public function testAnswersAnUnsupportedProtocolVersionWithA400(): void
    {
        $message = listToolsMessage();
        $message['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '2025-11-25';

        $response = $this->postJson('test-mcp', $message, [
            ...mcpHeaders($message),
            'MCP-Protocol-Version' => '2025-11-25',
        ]);

        $response->assertStatus(400);

        $this->assertEquals([
            'code' => -32022,
            'message' => 'Unsupported protocol version',
            'data' => [
                'supported' => ['2026-07-28'],
                'requested' => '2025-11-25',
            ],
        ], $response->json('error'));
    }

    public function testAnswersMissingProtocolMetadataWithA400(): void
    {
        $message = listToolsMessage();
        unset($message['params']['_meta']['io.modelcontextprotocol/clientCapabilities']);

        $response = $this->postJson('test-mcp', $message, mcpHeaders($message));

        $response->assertStatus(400);

        $this->assertSame(-32602, $response->json('error.code'));
    }

    #[DataProvider('missingMetadataMethods')]
    public function testRejectsMissingMetadataWhenTheRequestIdentifiesAModernProtocol(string $method): void
    {
        $response = $this->postJson('test-mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => $method], [
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
        ]);

        $response->assertStatus(400);
        $this->assertSame(3, $response->json('id'));
        $this->assertSame(-32602, $response->json('error.code'));
    }

    /**
     * Provide a modern-only method and a method identified by its version header.
     */
    public static function missingMetadataMethods(): array
    {
        return [['subscriptions/listen'], ['tools/list']];
    }

    public function testAnswersAnUnknownMethodWithA404(): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'unknown/method',
            'params' => ['_meta' => protocolMeta()],
        ];

        $response = $this->postJson('test-mcp', $message, mcpHeaders($message));

        $response->assertStatus(404);

        $this->assertSame(-32601, $response->json('error.code'));
    }

    public function testRequiresTheProtocolVersionHeaderEvenWhenTheBodyOmitsTheMetaMember(): void
    {
        $message = listToolsMessage();
        unset($message['params']['_meta']['io.modelcontextprotocol/protocolVersion']);

        $headers = mcpHeaders($message);
        unset($headers['MCP-Protocol-Version']);

        $response = $this->postJson('test-mcp', $message, $headers);

        $response->assertStatus(400);

        $this->assertEquals([
            'code' => -32020,
            'message' => 'Header mismatch: The [MCP-Protocol-Version] header is required.',
        ], $response->json('error'));
    }

    public function testSkipsHeaderValidationForALegacyRequestWithoutProtocolMetadata(): void
    {
        $response = $this->postJson('test-mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => []]);

        $response->assertStatus(200);

        $this->assertNotEmpty($response->json('result.tools'));
    }

    #[DataProvider('legacyVersions')]
    public function testServesToolsAfterTheLegacyInitializeHandshake(string $version, bool $withHeader): void
    {
        $this->postJson('test-mcp', [...initializeMessage(), 'params' => [
            'protocolVersion' => $version,
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
        ]])->assertOk()->assertJsonPath('result.protocolVersion', $version);

        $headers = $withHeader ? ['MCP-Protocol-Version' => $version] : [];

        $this->postJson('test-mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers)
            ->assertStatus(202);

        $response = $this->postJson('test-mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => []], $headers);

        $response->assertStatus(200);

        $this->assertContains('say-hi-tool', array_column($response->json('result.tools'), 'name'));

        $message = callToolMessage();
        unset($message['params']['_meta']);

        $this->postJson('test-mcp', $message, $headers)
            ->assertOk()->assertJsonPath('result.content.0.text', 'Hello, John Doe!');
    }

    /**
     * Provide supported legacy protocol versions with and without the negotiated version header.
     */
    public static function legacyVersions(): array
    {
        $cases = [];

        // REMOVED: The 2025-03-26 handshake; Hypervel does not negotiate that revision.
        foreach (['2025-11-25', '2025-06-18'] as $version) {
            foreach ([true, false] as $withHeader) {
                $cases[] = [$version, $withHeader];
            }
        }

        return $cases;
    }

    public function testAnswersALegacyRequestCarryingAnUnsupportedProtocolVersionHeaderWithA400(): void
    {
        $response = $this->postJson('test-mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => []], [
            'MCP-Protocol-Version' => '1900-01-01',
        ]);

        $response->assertStatus(400);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'id' => 3,
            'error' => [
                'code' => -32022,
                'message' => 'Unsupported protocol version',
                'data' => [
                    'supported' => ['2025-11-25', '2025-06-18'],
                    'requested' => '1900-01-01',
                ],
            ],
        ], $response->json());
    }

    public function testAnswersALegacyRequestCarryingAnInvalidUtf8ProtocolVersionHeaderWithA400(): void
    {
        $response = $this->postJson('test-mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => []], [
            'MCP-Protocol-Version' => "\xFF",
        ]);

        $response->assertStatus(400);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'id' => 3,
            'error' => [
                'code' => -32022,
                'message' => 'Unsupported protocol version',
                'data' => [
                    'supported' => ['2025-11-25', '2025-06-18'],
                    'requested' => '=?base64?/w==?=',
                ],
            ],
        ], $response->json());
    }

    public function testRequiresTheNameHeaderEvenWhenTheBodyCarriesNoUsableName(): void
    {
        $message = callToolMessage();
        $message['params']['name'] = 123;

        $headers = mcpHeaders($message);
        unset($headers['Mcp-Name']);

        $response = $this->postJson('test-mcp', $message, $headers);

        $response->assertStatus(400);

        $this->assertEquals([
            'code' => -32020,
            'message' => 'Header mismatch: The [Mcp-Name] header is required.',
        ], $response->json('error'));
    }

    public function testDoesNotDecodeTheBase64SentinelOnHeadersOtherThanTheName(): void
    {
        $message = callToolMessage();

        $response = $this->postJson('test-mcp', $message, [
            ...mcpHeaders($message),
            'Mcp-Method' => '=?base64?' . base64_encode('tools/call') . '?=',
        ]);

        $response->assertStatus(400);

        $this->assertEquals([
            'code' => -32020,
            'message' => 'Header mismatch: The [Mcp-Method] header value [=?base64?' . base64_encode('tools/call') . '?=] does not match the request body value [tools/call].',
        ], $response->json('error'));
    }

    public function testAnswersAnInternalErrorWithA500(): void
    {
        config(['app.debug' => false]);

        $this->app->bind(ListTools::class, fn (): ListTools => new class extends ListTools {
            /**
             * Fail the request with an internal error.
             */
            public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
            {
                throw new RuntimeException('Something exploded.');
            }
        });

        $message = listToolsMessage();

        $response = $this->postJson('test-mcp', $message, mcpHeaders($message));

        $response->assertStatus(500);

        $this->assertEquals([
            'code' => -32603,
            'message' => 'Something went wrong while processing the request.',
        ], $response->json('error'));
    }
}
