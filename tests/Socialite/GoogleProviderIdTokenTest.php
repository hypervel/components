<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\Exceptions\InvalidAudienceException;
use Hypervel\Socialite\Two\Exceptions\InvalidIssuerException;
use Hypervel\Socialite\Two\GoogleProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\Socialite\Fixtures\CreatesJwksFixtures;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use ReflectionMethod;

class GoogleProviderIdTokenTest extends TestCase
{
    use CreatesJwksFixtures;

    public function testItCanDetectJwtTokens(): void
    {
        $provider = $this->getProvider();
        $key = $this->createRsaKeyPair('current-key');

        $method = new ReflectionMethod($provider, 'isJwtToken');

        $this->assertTrue($method->invoke($provider, $this->createSignedToken($key)));
        $this->assertFalse($method->invoke($provider, 'ya29.a0AfH6SMCxyz123456789'));
    }

    #[DataProvider('validIssuerProvider')]
    public function testItAcceptsTheDocumentedIssuers(string $issuer): void
    {
        $provider = $this->getProvider();
        $key = $this->createRsaKeyPair('current-key');

        $this->expectJwksResponses($provider, [$key]);

        $user = $provider->userFromToken($this->createSignedToken($key, issuer: $issuer));

        $this->assertSame('123456789012345678901', $user->getId());
    }

    public static function validIssuerProvider(): array
    {
        return [
            'bare issuer' => ['accounts.google.com'],
            'HTTPS issuer' => ['https://accounts.google.com'],
        ];
    }

    public function testItRejectsAnInvalidIssuerWithTheNamedException(): void
    {
        $provider = $this->getProvider();
        $key = $this->createRsaKeyPair('current-key');

        $this->expectJwksResponses($provider, [$key]);
        $this->expectException(InvalidIssuerException::class);

        $provider->userFromToken($this->createSignedToken($key, issuer: 'https://invalid-issuer.example'));
    }

    public function testItRejectsAnInvalidAudienceWithTheNamedException(): void
    {
        $provider = $this->getProvider();
        $key = $this->createRsaKeyPair('current-key');

        $this->expectJwksResponses($provider, [$key]);
        $this->expectException(InvalidAudienceException::class);

        $provider->userFromToken($this->createSignedToken($key, audience: 'another-client'));
    }

    public function testItAcceptsConfiguredTrustedAudiences(): void
    {
        $provider = $this->getProvider();
        $provider->setConfig(['trusted_audiences' => 'trusted-api']);
        $key = $this->createRsaKeyPair('current-key');

        $this->expectJwksResponses($provider, [$key]);

        $user = $provider->userFromToken($this->createSignedToken(
            $key,
            audience: ['test-client-id', 'trusted-api'],
        ));

        $this->assertSame('123456789012345678901', $user->getId());
    }

    public function testItRefreshesJwksOnceForAChangedKey(): void
    {
        $provider = $this->getProvider();
        $oldKey = $this->createRsaKeyPair('old-key');
        $newKey = $this->createRsaKeyPair('new-key');

        $this->expectJwksResponses($provider, [$oldKey, $newKey]);

        $this->assertSame(
            '123456789012345678901',
            $provider->userFromToken($this->createSignedToken($oldKey))->getId(),
        );
        $this->assertSame(
            '123456789012345678901',
            $provider->userFromToken($this->createSignedToken($newKey))->getId(),
        );
    }

    public function testItFallsBackToApiCallForAccessTokens(): void
    {
        $provider = $this->getProvider();
        $accessToken = 'ya29.a0AfH6SMCxyz123456789';

        $httpClient = m::mock(Client::class);
        $provider->setHttpClient($httpClient);

        $response = m::mock(ResponseInterface::class);
        $stream = m::mock(StreamInterface::class);

        $mockUserData = [
            'sub' => '123456789',
            'email' => 'test@example.com',
            'name' => 'Test User',
            'picture' => 'https://example.com/photo.jpg',
        ];

        $httpClient
            ->shouldReceive('get')
            ->with('https://www.googleapis.com/oauth2/v3/userinfo', [
                RequestOptions::QUERY => [
                    'prettyPrint' => 'false',
                ],
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ])
            ->once()
            ->andReturn($response);

        $response->shouldReceive('getBody')->once()->andReturn($stream);

        $stream
            ->shouldReceive('__toString')
            ->once()
            ->andReturn(json_encode($mockUserData));

        $user = $provider->userFromToken($accessToken);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('123456789', $user->getId());
        $this->assertSame('test@example.com', $user->getEmail());
        $this->assertSame('Test User', $user->getName());
    }

    public function testUserMappingWorksWithIdTokenFormat(): void
    {
        $provider = $this->getProvider();

        $idTokenUser = [
            'sub' => '123456789012345678901',
            'email' => 'testuser@gmail.com',
            'email_verified' => true,
            'name' => 'Test User',
            'picture' => 'https://lh3.googleusercontent.com/photo.jpg',
        ];

        $method = new ReflectionMethod($provider, 'mapUserToObject');

        $user = $method->invoke($provider, $idTokenUser);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('123456789012345678901', $user->getId());
        $this->assertSame('testuser@gmail.com', $user->getEmail());
        $this->assertSame('Test User', $user->getName());
        $this->assertSame('https://lh3.googleusercontent.com/photo.jpg', $user->getAvatar());
    }

    /**
     * Get a GoogleProvider instance for testing.
     */
    protected function getProvider(): GoogleProvider
    {
        return new GoogleProvider(
            m::mock(Request::class),
            'test-client-id',
            'test-client-secret',
            'http://localhost/callback'
        );
    }

    private function expectJwksResponses(GoogleProvider $provider, array $keys): void
    {
        $httpClient = m::mock(Client::class);
        $provider->setHttpClient($httpClient);

        $httpClient->expects('get')
            ->with('https://www.googleapis.com/oauth2/v3/certs')
            ->times(count($keys))
            ->andReturn(...array_map(
                fn (array $key): Response => new Response(
                    headers: ['Cache-Control' => 'max-age=3600'],
                    body: json_encode($this->jwks($key)),
                ),
                $keys,
            ));
    }

    private function createSignedToken(
        array $key,
        string $issuer = 'https://accounts.google.com',
        array|string $audience = 'test-client-id',
    ): string {
        return JWT::encode([
            'iss' => $issuer,
            'sub' => '123456789012345678901',
            'aud' => $audience,
            'email' => 'testuser@gmail.com',
            'email_verified' => true,
            'name' => 'Test User',
            'picture' => 'https://lh3.googleusercontent.com/photo.jpg',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $key['private'], 'RS256', $key['kid']);
    }
}
