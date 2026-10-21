<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\Exceptions\InvalidIssuerException;
use Hypervel\Socialite\Two\Exceptions\InvalidNonceException;
use Hypervel\Socialite\Two\FacebookProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\Socialite\Fixtures\CreatesJwksFixtures;
use Hypervel\Tests\TestCase;
use Mockery as m;
use UnexpectedValueException;

use function Hypervel\Coroutine\parallel;

class FacebookProviderOIDCTokenTest extends TestCase
{
    use CreatesJwksFixtures;

    public function testItValidatesExpectedNonceForFacebookOidcTokens(): void
    {
        $provider = $this->getProvider();
        $key = $this->createRsaKeyPair('current-key');

        $this->expectJwksResponses($provider, [$key]);

        $user = $provider->userFromToken($this->createSignedToken($key), 'expected-nonce');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('123456', $user->getId());
        $this->assertSame('Test User', $user->getName());
        $this->assertSame('test@example.com', $user->getEmail());
    }

    public function testItValidatesExpectedNonceFromCustomParameters(): void
    {
        $provider = $this->getProvider();
        $key = $this->createRsaKeyPair('current-key');

        $this->expectJwksResponses($provider, [$key]);

        $user = $provider
            ->with(['nonce' => 'expected-nonce'])
            ->userFromToken($this->createSignedToken($key));

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('123456', $user->getId());
    }

    public function testItRejectsFacebookOidcTokensWithAnIncorrectNonce(): void
    {
        $provider = $this->getProvider();
        $key = $this->createRsaKeyPair('current-key');

        $this->expectJwksResponses($provider, [$key]);
        $this->expectException(InvalidNonceException::class);

        $provider->userFromToken($this->createSignedToken($key, nonce: 'unexpected-nonce'), 'expected-nonce');
    }

    public function testItRejectsFacebookOidcTokensWithoutAnExpectedNonce(): void
    {
        $provider = $this->getProvider();
        $key = $this->createRsaKeyPair('current-key');

        $this->expectJwksResponses($provider, [$key]);
        $this->expectException(InvalidNonceException::class);

        $provider->userFromToken($this->createSignedToken($key));
    }

    public function testItRejectsFacebookOidcTokensWithAnEmptyExpectedNonce(): void
    {
        $provider = $this->getProvider();
        $key = $this->createRsaKeyPair('current-key');

        $this->expectJwksResponses($provider, [$key]);
        $this->expectException(InvalidNonceException::class);

        $provider->userFromToken($this->createSignedToken($key, nonce: ''), '');
    }

    public function testItRejectsFacebookOidcTokensWithoutANonceWhenOneIsExpected(): void
    {
        $provider = $this->getProvider();
        $key = $this->createRsaKeyPair('current-key');

        $this->expectJwksResponses($provider, [$key]);
        $this->expectException(InvalidNonceException::class);

        $provider->userFromToken($this->createSignedToken($key, nonce: null), 'expected-nonce');
    }

    public function testExpectedNonceIsIsolatedBetweenCoroutines(): void
    {
        $provider = $this->getProvider();
        $key = $this->createRsaKeyPair('current-key');

        [$nonceA, $nonceB] = parallel([
            function () use ($provider, $key): string {
                $httpClient = m::mock(Client::class);
                $httpClient->expects('get')->andReturnUsing(function () use ($key): Response {
                    // Suspend this verification after its nonce is set.
                    usleep(5000);

                    return new Response(body: json_encode($this->jwks($key)));
                });
                $provider->setHttpClient($httpClient);

                return $provider->userFromToken($this->createSignedToken($key, nonce: 'nonce-a'), 'nonce-a')->getRaw()['nonce'];
            },
            function () use ($provider, $key): string {
                usleep(2500);

                $this->expectJwksResponses($provider, [$key]);

                return $provider->userFromToken($this->createSignedToken($key, nonce: 'nonce-b'), 'nonce-b')->getRaw()['nonce'];
            },
        ]);

        $this->assertSame('nonce-a', $nonceA);
        $this->assertSame('nonce-b', $nonceB);
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
        ), 'expected-nonce');

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

        $this->assertSame('123456', $provider->userFromToken($this->createSignedToken($oldKey), 'expected-nonce')->getId());
        $this->assertSame('123456', $provider->userFromToken($this->createSignedToken($newKey), 'expected-nonce')->getId());
    }

    public function testAnUnknownKidRaisesTheLibraryAuthenticationFailure(): void
    {
        $provider = $this->getProvider();
        $knownKey = $this->createRsaKeyPair('known-key');
        $unknownKey = $this->createRsaKeyPair('unknown-key');

        $this->expectJwksResponses($provider, [$knownKey, $knownKey]);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('"kid" invalid, unable to lookup correct key');

        $provider->userFromToken($this->createSignedToken($unknownKey));
    }

    /**
     * Get a FacebookProvider instance for testing.
     */
    private function getProvider(): FacebookProvider
    {
        return new FacebookProvider(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect',
        );
    }

    /**
     * Expect the provider to fetch the given keys from Facebook's key set, in order.
     */
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

    /**
     * Create a Limited Login token signed with the given key.
     */
    private function createSignedToken(
        array $key,
        string $issuer = 'https://www.facebook.com',
        array|string $audience = 'client_id',
        ?string $nonce = 'expected-nonce',
    ): string {
        return JWT::encode(array_filter([
            'iss' => $issuer,
            'sub' => '123456',
            'aud' => $audience,
            'nonce' => $nonce,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'picture' => 'https://platform-lookaside.fbsbx.com/oidc-photo.jpg',
            'iat' => time(),
            'exp' => time() + 3600,
        ], fn (mixed $value): bool => $value !== null), $key['private'], 'RS256', $key['kid']);
    }
}
