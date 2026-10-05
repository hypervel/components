<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Oauth2;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\OAuth2\GetAccessTokenRequest;
use Hypervel\Saloon\Http\OAuth2\GetClientCredentialsTokenBasicAuthRequest;
use Hypervel\Saloon\Http\OAuth2\GetClientCredentialsTokenRequest;
use Hypervel\Saloon\Http\OAuth2\GetRefreshTokenRequest;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class OAuthRequestTest extends TestCase
{
    #[DataProvider('requestBodies')]
    public function testTokenRequestsUseTheExpectedFormBody(Request $request, array $expected): void
    {
        $pendingRequest = $this->pendingRequest($request)
            ->bootPlugins()
            ->applyAuthentication()
            ->finalizeUri()
            ->prepareBody();

        $this->assertSame($expected, $pendingRequest->body());
        $this->assertSame('application/x-www-form-urlencoded', $pendingRequest->headers()['Content-Type']);
    }

    /**
     * Get each OAuth token request with its expected form body.
     *
     * @return array<string, array{Request, array<string, string>}>
     */
    public static function requestBodies(): array
    {
        $config = static::config();

        return [
            'authorization code' => [
                new GetAccessTokenRequest('code', $config, 'verifier'),
                [
                    'grant_type' => 'authorization_code',
                    'code' => 'code',
                    'client_id' => 'client',
                    'client_secret' => 'secret',
                    'redirect_uri' => 'https://app.example.com/callback',
                    'code_verifier' => 'verifier',
                ],
            ],
            'refresh token' => [
                new GetRefreshTokenRequest($config, 'refresh'),
                [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => 'refresh',
                    'client_id' => 'client',
                    'client_secret' => 'secret',
                ],
            ],
            'client credentials' => [
                new GetClientCredentialsTokenRequest($config, ['profile']),
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => 'client',
                    'client_secret' => 'secret',
                    'scope' => 'openid profile',
                ],
            ],
            'client credentials basic auth' => [
                new GetClientCredentialsTokenBasicAuthRequest($config, ['profile']),
                [
                    'grant_type' => 'client_credentials',
                    'scope' => 'openid profile',
                ],
            ],
        ];
    }

    /**
     * Create the OAuth configuration used by request tests.
     */
    protected static function config(): OAuthConfig
    {
        return new OAuthConfig(
            clientId: 'client',
            clientSecret: 'secret',
            redirectUri: 'https://app.example.com/callback',
            defaultScopes: ['openid'],
        );
    }

    /**
     * Create a pending request with isolated framework dependencies.
     */
    protected function pendingRequest(Request $request): PendingRequest
    {
        return new PendingRequest(
            new TestConnector('https://api.example.com'),
            $request,
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        );
    }
}
