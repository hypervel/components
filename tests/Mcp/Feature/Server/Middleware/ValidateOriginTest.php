<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Server\Middleware;

use Hypervel\Http\Request;
use Hypervel\Mcp\Server\Middleware\ValidateOrigin;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

use function Hypervel\Tests\Mcp\Fixtures\listToolsMessage;
use function Hypervel\Tests\Mcp\Fixtures\mcpHeaders;

class ValidateOriginTest extends TestCase
{
    public function testAcceptsARequestWithoutAnOriginHeader(): void
    {
        $message = listToolsMessage();

        $this->postJson('test-mcp', $message, mcpHeaders($message))->assertStatus(200);
    }

    #[DataProvider('allowedOrigins')]
    public function testAcceptsARequestFromAnAllowedOrigin(string $origin): void
    {
        config(['app.url' => 'https://app.test/']);

        $message = listToolsMessage();

        $this->postJson('test-mcp', $message, [...mcpHeaders($message), 'Origin' => $origin])->assertStatus(200);
    }

    /**
     * Provide origins matching the application URL.
     */
    public static function allowedOrigins(): array
    {
        // REMOVED: Upstream's localhost, IPv4 and IPv6 loopback rows; Hypervel trusts loopback origins only when configured.
        return [
            'application url' => ['https://app.test'],
            'application url with default port' => ['https://APP.test:443'],
        ];
    }

    #[DataProvider('disallowedOrigins')]
    public function testRejectsARequestFromAnOriginThatIsNotAllowed(string $origin): void
    {
        config(['app.url' => 'https://app.test']);

        $message = listToolsMessage();

        $response = $this->postJson('test-mcp', $message, [...mcpHeaders($message), 'Origin' => $origin]);

        $response->assertStatus(403);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32600,
                'message' => 'Forbidden: The request origin is not allowed.',
            ],
        ], $response->json());
    }

    /**
     * Provide unapproved and malformed origins.
     */
    public static function disallowedOrigins(): array
    {
        return [
            'foreign host' => ['https://evil.example'],
            'subdomain of application' => ['https://evil.app.test'],
            'different scheme' => ['http://app.test'],
            'different port' => ['https://app.test:8443'],
            'opaque origin' => ['null'],
            'unsupported scheme' => ['file://app.test'],
            'loopback' => ['http://localhost:6274'],
            'empty' => [''],
            'path' => ['https://app.test/path'],
            'trailing slash' => ['https://app.test/'],
            'credentials' => ['https://user@app.test'],
            'query' => ['https://app.test?query'],
            'fragment' => ['https://app.test#fragment'],
            'invalid port' => ['https://app.test:99999'],
        ];
    }

    public function testRejectsARebindingRequestWhoseHostMatchesItsOrigin(): void
    {
        config(['app.url' => 'https://app.test']);

        $message = listToolsMessage();

        $this->postJson('http://evil.example/test-mcp', $message, [...mcpHeaders($message), 'Origin' => 'http://evil.example'])
            ->assertStatus(403);
    }

    public function testAcceptsTheApplicationOriginWhenItsUrlHasAPath(): void
    {
        config(['app.url' => 'https://app.test/subdirectory']);

        $message = listToolsMessage();

        $this->postJson('test-mcp', $message, [...mcpHeaders($message), 'Origin' => 'https://app.test'])->assertStatus(200);
    }

    public function testAcceptsARequestFromAConfiguredOrigin(): void
    {
        config(['mcp.allowed_origins' => ['https://client.example/']]);

        $message = listToolsMessage();

        $this->postJson('test-mcp', $message, [...mcpHeaders($message), 'Origin' => 'https://client.example'])->assertStatus(200);
        $this->postJson('test-mcp', $message, [...mcpHeaders($message), 'Origin' => 'https://evil.example'])->assertStatus(403);
    }

    #[TestWith(['https://client.example/app'])]
    #[TestWith(['https://client.example/?app=1'])]
    #[TestWith(['https://client.example/#app'])]
    public function testIgnoresAConfiguredEntryWithAPathQueryOrFragment(string $allowed): void
    {
        config(['mcp.allowed_origins' => [$allowed]]);

        $message = listToolsMessage();

        $this->postJson('test-mcp', $message, [...mcpHeaders($message), 'Origin' => 'https://client.example'])->assertStatus(403);
    }

    public function testAcceptsAConfiguredOriginGivenAsAString(): void
    {
        config(['mcp.allowed_origins' => 'https://client.example']);

        $message = listToolsMessage();

        $this->postJson('test-mcp', $message, [...mcpHeaders($message), 'Origin' => 'https://client.example'])->assertStatus(200);
        $this->postJson('test-mcp', $message, [...mcpHeaders($message), 'Origin' => 'https://evil.example'])->assertStatus(403);
    }

    public function testIgnoresConfiguredOriginsThatAreNotStrings(): void
    {
        config(['mcp.allowed_origins' => [null, 42, 'https://client.example']]);

        $message = listToolsMessage();

        $this->postJson('test-mcp', $message, [...mcpHeaders($message), 'Origin' => 'https://client.example'])->assertStatus(200);
        $this->postJson('test-mcp', $message, [...mcpHeaders($message), 'Origin' => 'https://evil.example'])->assertStatus(403);
    }

    public function testAcceptsAnyOriginWhenAllOriginsAreAllowed(): void
    {
        config(['mcp.allowed_origins' => ['*']]);

        $message = listToolsMessage();

        $this->postJson('test-mcp', $message, [...mcpHeaders($message), 'Origin' => 'https://evil.example'])->assertStatus(200);
        $this->postJson('test-mcp', $message, [...mcpHeaders($message), 'Origin' => 'null'])->assertStatus(403);
    }

    #[TestWith(['GET'])]
    #[TestWith(['DELETE'])]
    public function testValidatesTheOriginOnGetAndDeleteRequests(string $method): void
    {
        $this->json($method, 'test-mcp', [], ['Origin' => 'https://evil.example'])->assertStatus(403);
        $this->json($method, 'test-mcp')->assertStatus(405);
    }

    public function testRejectsMultipleOriginHeaders(): void
    {
        config(['app.url' => 'https://app.test']);

        $request = Request::create('https://app.test/test-mcp', 'POST');
        $request->headers->set('Origin', ['https://app.test', 'https://other.test']);

        $response = $this->app->make(ValidateOrigin::class)->handle($request, function (): never {
            $this->fail('The request must be rejected before reaching the handler.');
        });

        $this->assertSame(403, $response->getStatusCode());
    }
}
