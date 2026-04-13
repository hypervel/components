<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\GoogleProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class GoogleProviderIdTokenTest extends TestCase
{
    public function testItCanDetectJwtTokens()
    {
        $provider = $this->getProvider();

        $jwtToken = $this->createMockJwtToken();
        $accessToken = 'ya29.a0AfH6SMCxyz123456789';

        $method = new ReflectionMethod($provider, 'isJwtToken');

        $this->assertTrue($method->invoke($provider, $jwtToken));
        $this->assertFalse($method->invoke($provider, $accessToken));
    }

    public function testItUsesJwtVerificationForIdTokens()
    {
        $provider = $this->getProvider();
        $idToken = $this->createMockJwtToken();

        $this->mockJwksResponse($provider);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Failed to verify Google JWT token/');

        $provider->userFromToken($idToken);
    }

    public function testItFallsBackToApiCallForAccessTokens()
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

    #[DataProvider('invalidJwtProvider')]
    public function testItHandlesInvalidJwtTokens(string $description, array $tokenOverrides, bool $expectedException = true)
    {
        $provider = $this->getProvider();
        $invalidToken = $this->createInvalidJwtToken($tokenOverrides);

        if ($expectedException) {
            $this->mockJwksResponse($provider);
            $this->expectException(Exception::class);
            $this->expectExceptionMessageMatches('/Failed to verify Google JWT token/');
        }

        $provider->userFromToken($invalidToken);
    }

    public static function invalidJwtProvider(): array
    {
        return [
            'invalid issuer' => [
                'Invalid issuer',
                ['payload' => ['iss' => 'https://invalid-issuer.com']],
            ],
            'invalid audience' => [
                'Invalid audience',
                ['payload' => ['aud' => 'wrong-client-id']],
            ],
            'missing key id' => [
                'Missing key ID',
                ['header' => ['kid' => null]],
            ],
        ];
    }

    public function testUserMappingWorksWithIdTokenFormat()
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

    /**
     * Create a mock JWT token for testing.
     */
    protected function createMockJwtToken(): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'test-key-id'];
        $payload = [
            'iss' => 'https://accounts.google.com',
            'sub' => '123456789012345678901',
            'aud' => 'test-client-id',
            'email' => 'testuser@gmail.com',
            'email_verified' => true,
            'name' => 'Test User',
            'picture' => 'https://lh3.googleusercontent.com/photo.jpg',
            'iat' => time(),
            'exp' => time() + 3600,
        ];

        return $this->base64UrlEncode(json_encode($header))
            . '.'
            . $this->base64UrlEncode(json_encode($payload))
            . '.'
            . $this->base64UrlEncode('mock-signature');
    }

    /**
     * Mock JWKS response for testing JWT verification.
     */
    protected function mockJwksResponse(GoogleProvider $provider): void
    {
        $httpClient = m::mock(Client::class);
        $provider->setHttpClient($httpClient);

        $jwksResponse = m::mock(ResponseInterface::class);
        $jwksStream = m::mock(StreamInterface::class);

        $mockJwks = [
            'keys' => [
                [
                    'kid' => 'test-key-id',
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'n' => 'mock-n-value',
                    'e' => 'AQAB',
                ],
            ],
        ];

        $httpClient->shouldReceive('get')
            ->with('https://www.googleapis.com/oauth2/v3/certs')
            ->once()
            ->andReturn($jwksResponse);

        $jwksResponse->shouldReceive('getBody')->once()->andReturn($jwksStream);
        $jwksStream->shouldReceive('__toString')->once()->andReturn(json_encode($mockJwks));
    }

    /**
     * Create an invalid JWT token for testing.
     */
    protected function createInvalidJwtToken(array $overrides): string
    {
        $header = array_merge(
            ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'test-key-id'],
            $overrides['header'] ?? []
        );

        $payload = array_merge([
            'iss' => 'https://accounts.google.com',
            'sub' => '123456789',
            'aud' => 'test-client-id',
            'email' => 'test@example.com',
            'name' => 'Test User',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $overrides['payload'] ?? []);

        $header = array_filter($header, function ($value) {
            return $value !== null;
        });

        return $this->base64UrlEncode(json_encode($header))
            . '.'
            . $this->base64UrlEncode(json_encode($payload))
            . '.'
            . $this->base64UrlEncode('mock-signature');
    }

    /**
     * Base64URL encode data.
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
