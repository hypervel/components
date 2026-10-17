<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Console;

use Hypervel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Hypervel\Mcp\Server\Registrar;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Mcp\TestCase;
use Symfony\Component\Process\Process;

use function Hypervel\Tests\Mcp\Fixtures\callStreamingToolMessage;
use function Hypervel\Tests\Mcp\Fixtures\callToolMessage;
use function Hypervel\Tests\Mcp\Fixtures\discoverMessage;
use function Hypervel\Tests\Mcp\Fixtures\expectedCallToolResponse;
use function Hypervel\Tests\Mcp\Fixtures\expectedDiscoverResponse;
use function Hypervel\Tests\Mcp\Fixtures\expectedListResourcesResponse;
use function Hypervel\Tests\Mcp\Fixtures\expectedListToolsResponse;
use function Hypervel\Tests\Mcp\Fixtures\expectedReadResourceResponse;
use function Hypervel\Tests\Mcp\Fixtures\expectedStreamingToolResponse;
use function Hypervel\Tests\Mcp\Fixtures\initializeMessage;
use function Hypervel\Tests\Mcp\Fixtures\listResourcesMessage;
use function Hypervel\Tests\Mcp\Fixtures\listToolsMessage;
use function Hypervel\Tests\Mcp\Fixtures\mcpHeaders;
use function Hypervel\Tests\Mcp\Fixtures\parseJsonRpcMessagesFromSseStream;
use function Hypervel\Tests\Mcp\Fixtures\parseJsonRpcMessagesFromStdout;
use function Hypervel\Tests\Mcp\Fixtures\readResourceMessage;

class StartCommandTest extends TestCase
{
    public function testAnswersTheLegacyInitializeHandshakeOverHttp(): void
    {
        $response = $this->postJson('test-mcp', initializeMessage());

        $response->assertStatus(200);

        $this->assertSame('2025-11-25', $response->json('result.protocolVersion'));
    }

    public function testServesLegacyRequestsWithoutMcpHeadersOverHttp(): void
    {
        $response = $this->postJson('test-mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);

        $response->assertStatus(200);

        $this->assertNotEmpty($response->json('result.tools'));
    }

    public function testDoesNotReturnASessionIdOverHttp(): void
    {
        $response = $this->postJson('test-mcp', $message = discoverMessage(), mcpHeaders($message));

        $response->assertStatus(200);
        $response->assertHeaderMissing('MCP-Session-Id');

        $this->assertEquals(expectedDiscoverResponse(), $response->json());
    }

    public function testIgnoresAnInboundSessionIdHeader(): void
    {
        $response = $this->postJson('test-mcp', $message = listToolsMessage(), [
            ...mcpHeaders($message),
            'MCP-Session-Id' => 'stale-session',
        ]);

        $response->assertStatus(200);
        $response->assertHeaderMissing('MCP-Session-Id');

        $this->assertEquals(expectedListToolsResponse(), $response->json());
    }

    public function testHandlesConsecutiveRequestsWithoutAnyPriorHandshake(): void
    {
        $first = $this->postJson('test-mcp', $message = listToolsMessage(), mcpHeaders($message));
        $second = $this->postJson('test-mcp', $message = callToolMessage(), mcpHeaders($message));

        $first->assertStatus(200);
        $second->assertStatus(200);

        $this->assertEquals(expectedListToolsResponse(), $first->json());
        $this->assertEquals(expectedCallToolResponse(), $second->json());
    }

    public function testCanListResourcesOverHttp(): void
    {
        $response = $this->postJson('test-mcp', $message = listResourcesMessage(), mcpHeaders($message));

        $response->assertStatus(200);

        $this->assertEquals(expectedListResourcesResponse(), $response->json());
    }

    public function testCanReadAResourceOverHttp(): void
    {
        $response = $this->postJson('test-mcp', $message = readResourceMessage(), mcpHeaders($message));

        $response->assertStatus(200);

        $this->assertEquals(expectedReadResourceResponse(), $response->json());
    }

    public function testCanListToolsOverHttp(): void
    {
        $response = $this->postJson('test-mcp', $message = listToolsMessage(), mcpHeaders($message));

        $response->assertStatus(200);

        $this->assertEquals(expectedListToolsResponse(), $response->json());
    }

    public function testCanCallAToolOverHttp(): void
    {
        $response = $this->postJson('test-mcp', $message = callToolMessage(), mcpHeaders($message));

        $response->assertStatus(200);

        $this->assertEquals(expectedCallToolResponse(), $response->json());
    }

    public function testCanStreamAToolResponseOverHttp(): void
    {
        $response = $this->postJson(
            'test-mcp',
            $message = callStreamingToolMessage(),
            [...mcpHeaders($message), 'Accept' => 'text/event-stream'],
        );

        $response->assertStatus(200);

        $this->assertSame('text/event-stream; charset=utf-8', strtolower((string) $response->headers->get('Content-Type')));

        $content = $response->streamedContent();
        $messages = parseJsonRpcMessagesFromSseStream($content);

        $this->assertEquals(expectedStreamingToolResponse(), $messages);
    }

    public function testCanDiscoverAServerOverStdio(): void
    {
        $process = $this->remoteServer();
        $process->setInput(json_encode(discoverMessage()));

        $this->assertSame(0, $process->run(), $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertEquals(expectedDiscoverResponse(), json_decode($process->getOutput(), true));
    }

    public function testCanListToolsOverStdio(): void
    {
        $process = $this->remoteServer();
        $process->setInput(json_encode(listToolsMessage()));

        $process->run();

        $output = json_decode($process->getOutput(), true);

        $this->assertEquals(expectedListToolsResponse(), $output);
    }

    public function testCanCallAToolOverStdio(): void
    {
        $process = $this->remoteServer();
        $process->setInput(json_encode(callToolMessage()));

        $process->run();

        $output = json_decode($process->getOutput(), true);

        $this->assertEquals(expectedCallToolResponse(), $output);
    }

    public function testCanStreamAToolResponseOverStdio(): void
    {
        $process = $this->remoteServer();
        $process->setInput(json_encode(callStreamingToolMessage()));

        $process->run();

        $output = $process->getOutput();
        $messages = parseJsonRpcMessagesFromStdout($output);

        $this->assertEquals(expectedStreamingToolResponse(), $messages);
    }

    public function testCanListDynamicallyAddedTools(): void
    {
        $response = $this->postJson('test-mcp-dynamic-tools', $message = listToolsMessage(), mcpHeaders($message));

        $response->assertStatus(200);

        $this->assertEquals(expectedListToolsResponse(), $response->json());
    }

    public function testReturns405ForGetRequestsToMcpWebRoutes(): void
    {
        $response = $this->get('test-mcp');

        $response->assertStatus(405);
        $response->assertHeader('Allow', 'POST');
    }

    public function testReturns405ForDeleteRequestsToMcpWebRoutes(): void
    {
        $response = $this->delete('test-mcp');

        $response->assertStatus(405);
        $response->assertHeader('Allow', 'POST');
    }

    public function testReturnsOAuthAuthenticateHeaderWhenOAuthRoutesAreEnabledAndResponseIs401(): void
    {
        // Enable OAuth routes which registers the 'mcp.oauth.protected-resource' route
        app(Registrar::class)->oauthRoutes();

        // Create a test route that returns 401 to trigger the middleware
        Route::post('test-oauth-401', fn () => response()->json(['error' => 'unauthorized'], 401))->middleware([AddWwwAuthenticateHeader::class]);

        $response = $this->postJson('test-oauth-401', []);

        $response->assertStatus(401);
        $response->assertHeader('WWW-Authenticate');

        $wwwAuth = $response->headers->get('WWW-Authenticate');
        $this->assertStringContainsString('Bearer realm="mcp"', $wwwAuth);
        $this->assertStringContainsString('resource_metadata="' . url('/.well-known/oauth-protected-resource/test-oauth-401') . '"', $wwwAuth);
    }

    public function testReturnsOAuthAuthenticateHeaderForNestedMcpPaths(): void
    {
        app(Registrar::class)->oauthRoutes();

        Route::post('mcp/nested/test-oauth-401', fn () => response()->json(['error' => 'unauthorized'], 401))
            ->middleware([AddWwwAuthenticateHeader::class]);

        $response = $this->postJson('mcp/nested/test-oauth-401', []);

        $response->assertStatus(401);
        $response->assertHeader('WWW-Authenticate');

        $wwwAuth = $response->headers->get('WWW-Authenticate');
        $this->assertStringContainsString('Bearer realm="mcp"', $wwwAuth);
        $this->assertStringContainsString(
            'resource_metadata="' . url('/.well-known/oauth-protected-resource/mcp/nested/test-oauth-401') . '"',
            $wwwAuth
        );
    }

    public function testReturnsSanctumAuthenticateHeaderWhenOAuthRoutesAreNotEnabledAndResponseIs401(): void
    {
        // Create a test route that returns 401 to trigger the middleware
        Route::post('test-sanctum-401', fn () => response()->json(['error' => 'unauthorized'], 401))->middleware([AddWwwAuthenticateHeader::class]);

        $response = $this->postJson('test-sanctum-401', []);

        $response->assertStatus(401);
        $response->assertHeader('WWW-Authenticate');

        $wwwAuth = $response->headers->get('WWW-Authenticate');
        $this->assertSame('Bearer realm="mcp", error="invalid_token"', $wwwAuth);
    }

    public function testDoesNotAddAuthenticateHeaderWhenResponseIsNot401(): void
    {
        app(Registrar::class)->oauthRoutes();

        $response = $this->postJson('test-mcp', $message = listToolsMessage(), mcpHeaders($message));

        $response->assertStatus(200);
        $response->assertHeaderMissing('WWW-Authenticate');
    }

    /**
     * Create a bounded command process with the package test registrations.
     */
    private function remoteServer(): Process
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/../../Fixtures/Console/start.php'], timeout: 5);
        $this->beforeApplicationDestroyed(fn (): ?int => $process->stop(0));

        return $process;
    }
}
