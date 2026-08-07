<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\Exceptions\InvalidIssuerException;
use Hypervel\Socialite\Two\FacebookProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;
use UnexpectedValueException;

class FacebookProviderTest extends TestCase
{
    public function testMapUserToObjectWithAccessTokenResponse(): void
    {
        $provider = $this->getProvider();

        $method = new ReflectionMethod($provider, 'mapUserToObject');

        $user = $method->invoke($provider, [
            'id' => '123456',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'link' => 'https://facebook.com/testuser',
            'picture' => [
                'data' => [
                    'url' => 'https://platform-lookaside.fbsbx.com/photo.jpg',
                ],
            ],
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('https://platform-lookaside.fbsbx.com/photo.jpg', $user->getAvatar());
        $this->assertSame('https://platform-lookaside.fbsbx.com/photo.jpg', $user->avatar_original);
    }

    public function testMapUserToObjectWithOidcTokenResponse(): void
    {
        $provider = $this->getProvider();

        $method = new ReflectionMethod($provider, 'mapUserToObject');

        $user = $method->invoke($provider, [
            'sub' => '123456',
            'id' => '123456',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'picture' => 'https://platform-lookaside.fbsbx.com/oidc-photo.jpg',
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('https://platform-lookaside.fbsbx.com/oidc-photo.jpg', $user->getAvatar());
        $this->assertSame('https://platform-lookaside.fbsbx.com/oidc-photo.jpg', $user->avatar_original);
    }

    public function testItAcceptsConfiguredTrustedAudiences(): void
    {
        $provider = $this->getProvider();
        $provider->setConfig(['trusted_audiences' => ['trusted-api']]);
        $key = $this->createRsaKeyPair('current-key');

        $this->expectJwksResponses($provider, [$key]);

        $user = $provider->userFromToken($this->createSignedToken(
            $key,
            audience: ['client_id', 'trusted-api'],
        ));

        $this->assertSame('123456', $user->getId());
    }

    public function testItRejectsAnInvalidIssuerWithTheNamedException(): void
    {
        $provider = $this->getProvider();
        $key = $this->createRsaKeyPair('current-key');

        $this->expectJwksResponses($provider, [$key]);
        $this->expectException(InvalidIssuerException::class);

        $provider->userFromToken($this->createSignedToken($key, issuer: 'https://invalid-issuer.example'));
    }

    public function testItRefreshesJwksOnceForAChangedKey(): void
    {
        $provider = $this->getProvider();
        $oldKey = $this->createRsaKeyPair('old-key');
        $newKey = $this->createRsaKeyPair('new-key');

        $this->expectJwksResponses($provider, [$oldKey, $newKey]);

        $this->assertSame('123456', $provider->userFromToken($this->createSignedToken($oldKey))->getId());
        $this->assertSame('123456', $provider->userFromToken($this->createSignedToken($newKey))->getId());
    }

    public function testAnUnknownKidRaisesTheLibraryAuthenticationFailure(): void
    {
        $provider = $this->getProvider();
        $knownKey = $this->createRsaKeyPair('known-key');
        $unknownKey = $this->createRsaKeyPair('unknown-key');

        $this->expectJwksResponses($provider, [$knownKey, $knownKey]);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('"kid" invalid, unable to lookup correct key');

        $provider->userFromToken($this->createSignedToken($unknownKey));
    }

    public function testAccessTokenProfileRequestPreservesDocumentedQueryParameters(): void
    {
        $provider = $this->getProvider();
        $httpClient = m::mock(Client::class);
        $provider->setHttpClient($httpClient);

        $httpClient->expects('get')->with('https://graph.facebook.com/v23.0/me', [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
            ],
            RequestOptions::QUERY => [
                'access_token' => 'access-token',
                'fields' => 'name,email,gender,verified,link,picture.width(1920)',
                'appsecret_proof' => hash_hmac('sha256', 'access-token', 'client_secret'),
            ],
        ])->andReturn(new Response(body: json_encode([
            'id' => '123456',
            'name' => 'Test User',
        ])));

        $this->assertSame('123456', $provider->userFromToken('access-token')->getId());
    }

    private function getProvider(): FacebookProvider
    {
        return new FacebookProvider(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect',
        );
    }

    private function expectJwksResponses(FacebookProvider $provider, array $keys): void
    {
        $httpClient = m::mock(Client::class);
        $provider->setHttpClient($httpClient);

        $httpClient->expects('get')
            ->with('https://limited.facebook.com/.well-known/oauth/openid/jwks/')
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
        string $issuer = 'https://www.facebook.com',
        array|string $audience = 'client_id',
    ): string {
        return JWT::encode([
            'iss' => $issuer,
            'sub' => '123456',
            'aud' => $audience,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'picture' => 'https://platform-lookaside.fbsbx.com/oidc-photo.jpg',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $key['private'], 'RS256', $key['kid']);
    }

    private function createRsaKeyPair(string $kid): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            $this->fail('Unable to generate RSA key pair for Facebook ID token test.');
        }

        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);

        return [
            'kid' => $kid,
            'private' => $privateKey,
            'jwk' => [
                'kid' => $kid,
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $this->base64UrlEncode($details['rsa']['n']),
                'e' => $this->base64UrlEncode($details['rsa']['e']),
            ],
        ];
    }

    private function jwks(array $key): array
    {
        return ['keys' => [$key['jwk']]];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
