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

    public function testDecodesABase64SentinelNameHeaderBeforeComparingIt(): void
    {
        $message = callToolMessage();

        $response = $this->postJson('test-mcp', $message, [
            ...mcpHeaders($message),
            'Mcp-Name' => '=?base64?' . base64_encode((string) $message['params']['name']) . '?=',
        ]);

        $response->assertStatus(200);
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
     * Provide modern-only methods and a method identified by its version header.
     */
    public static function missingMetadataMethods(): array
    {
        return [['server/discover'], ['subscriptions/listen'], ['tools/list']];
    }

    #[DataProvider('unsupportedModernMethods')]
    public function testAnswersAnUnsupportedModernMethodWithA404(string $method): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => $method,
            'params' => ['_meta' => protocolMeta()],
        ];

        $response = $this->postJson('test-mcp', $message, mcpHeaders($message));

        $response->assertStatus(404);

        $this->assertSame(-32601, $response->json('error.code'));
    }

    /**
     * Provide unknown methods and methods removed from the modern protocol.
     */
    public static function unsupportedModernMethods(): array
    {
        return [['unknown/method'], ['initialize'], ['ping']];
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
    public function testAcceptsALegacyRequestCarryingANegotiatedProtocolVersionHeader(string $version): void
    {
        $response = $this->postJson('test-mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => []], [
            'MCP-Protocol-Version' => $version,
        ]);

        $response->assertStatus(200);

        $this->assertNotEmpty($response->json('result.tools'));
    }

    /**
     * Provide supported legacy protocol versions.
     */
    public static function legacyVersions(): array
    {
        return [['2025-11-25'], ['2025-06-18']];
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

        $this->assertSame(-32020, $response->json('error.code'));
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
