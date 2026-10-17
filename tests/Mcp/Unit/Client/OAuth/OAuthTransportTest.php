<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client\OAuth;

use Hypervel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Hypervel\Mcp\Client\OAuth\WwwAuthenticateChallenge;
use Hypervel\Mcp\Client\Transport\HttpTransport;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Mcp\TestCase;

class OAuthTransportTest extends TestCase
{
    public function testResolvesAClosureTokenAtRequestTime(): void
    {
        Http::fake([
            'https://mcp.test/mcp' => Http::response(
                json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]]),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $transport = new HttpTransport('https://mcp.test/mcp');
        $transport->withToken(fn (): string => 'dynamic-token');
        try {
            $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => []]));
            Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer dynamic-token'));
        } finally {
            $transport->endExchange();
        }
    }

    public function testThrowsAuthorizationRequiredExceptionOnA401WithAChallenge(): void
    {
        Http::fake([
            'https://mcp.test/mcp' => Http::response('', 401, [
                'WWW-Authenticate' => 'Bearer error="invalid_token", resource_metadata="https://mcp.test/.well-known/oauth-protected-resource/mcp", scope="files:read"',
            ]),
        ]);

        $transport = new HttpTransport('https://mcp.test/mcp');

        try {
            $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]));
            $this->fail('Expected AuthorizationRequiredException.');
        } catch (AuthorizationRequiredException $authorizationRequiredException) {
            $this->assertSame('https://mcp.test/.well-known/oauth-protected-resource/mcp', $authorizationRequiredException->resourceMetadataUrl());
            $this->assertSame('invalid_token', $authorizationRequiredException->challenge?->error);
            $this->assertSame('files:read', $authorizationRequiredException->scope());
        }
    }

    public function testThrowsAuthorizationRequiredExceptionOnA403(): void
    {
        Http::fake([
            'https://mcp.test/mcp' => Http::response('', 403),
        ]);

        $transport = new HttpTransport('https://mcp.test/mcp');

        $this->expectException(AuthorizationRequiredException::class);
        $transport->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]));
    }

    public function testParsesMixedWwwAuthenticateChallengesWithQuotedCommasAndUnquotedValues(): void
    {
        $challenge = WwwAuthenticateChallenge::parse('Basic realm="api", Bearer resource_metadata="https://mcp.test/.well-known/oauth-protected-resource/a,b", scope=files:read, error_description="needs access"');

        $this->assertSame('https://mcp.test/.well-known/oauth-protected-resource/a,b', $challenge->resourceMetadataUrl);
        $this->assertSame('files:read', $challenge->scope);
        $this->assertSame('needs access', $challenge->errorDescription);
    }

    public function testEscapedQuotedValuesDoNotIntroduceChallengeParameters(): void
    {
        $challenge = WwwAuthenticateChallenge::parse(<<<'HEADER'
Bearer scope="files:read", realm="example\", scope=admin", error_description="use \"quoted\" text and \\slashes"
HEADER);

        $this->assertSame('files:read', $challenge->scope);
        $this->assertSame('use "quoted" text and \slashes', $challenge->errorDescription);
    }

    public function testExposesTheConfiguredEndpointUrl(): void
    {
        $this->assertSame('https://mcp.test/mcp', (new HttpTransport('https://mcp.test/mcp'))->url());
    }
}
