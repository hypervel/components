<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\Exceptions\InvalidAudienceException;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\Socialite\Fixtures\OpenIdTestProviderStub;
use Hypervel\Tests\Socialite\Fixtures\VerifyingOpenIdTestProviderStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use ReflectionMethod;
use UnexpectedValueException;

class OpenIdProviderTest extends TestCase
{
    public function testRedirectGeneratesTheProperRedirectResponseWithoutPKCE()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('session')
            ->andReturn($session = m::mock(SessionContract::class));

        $state = null;
        $nonce = null;
        $closure = function ($name, $stateInput) use (&$state, &$nonce) {
            if ($name === 'state') {
                $state = $stateInput;

                return true;
            }
            if ($name === 'nonce') {
                $nonce = $stateInput;

                return true;
            }

            return false;
        };

        $session->expects('put')->twice()->withArgs($closure);
        $provider = new OpenIdTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect'
        );

        $response = $provider->redirect();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(
            "http://auth.url?client_id=client_id&redirect_uri=redirect&scope=&response_type=code&state={$state}&nonce={$nonce}",
            $response->getTargetUrl()
        );
    }

    public function testUserReturnsAUserInstanceForTheAuthenticatedRequest()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('session')
            ->andReturn($session = m::mock(SessionContract::class));
        $request->shouldReceive('has')
            ->andReturn(true);
        $request->shouldReceive('input')
            ->with('state')
            ->once()
            ->andReturn(str_repeat('A', 40));
        $request->shouldReceive('input')
            ->with('code')
            ->once()
            ->andReturn('code');

        $session->expects('pull')->with('state')->andReturns(str_repeat('A', 40));
        $session->expects('has')->with('nonce')->andReturns(true);
        $session->expects('get')->with('nonce')->andReturns('nonce');
        $provider = new OpenIdTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect_uri'
        );
        $provider->http = m::mock(Client::class);
        $provider->http->expects('get')->with('http://base.url/.well-known/openid-configuration')
            ->andReturns(new Response(
                body: json_encode([
                    'issuer' => 'http://base.url',
                    'token_endpoint' => 'http://token.url',
                    'jwks_uri' => 'http://jwks.url',
                ])
            ));
        $provider->http->expects('post')->with('http://token.url', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['grant_type' => 'authorization_code', 'client_id' => 'client_id', 'client_secret' => 'client_secret', 'code' => 'code', 'redirect_uri' => 'redirect_uri'],
        ])->andReturns($response = m::mock(ResponseInterface::class));
        $stream = m::mock(StreamInterface::class);
        $stream->allows('__toString')->andReturns('{ "access_token" : "access_token", "id_token" : "id_token", "refresh_token" : "refresh_token", "expires_in" : 3600 }');
        $response->expects('getBody')->andReturns($stream);
        $user = $provider->user();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('foo', $user->id);
        $this->assertSame('access_token', $user->token);
        $this->assertSame('refresh_token', $user->refreshToken);
        $this->assertSame(3600, $user->expiresIn);
        $this->assertSame($user->id, $provider->user()->id);
    }

    public function testSetConfigOverridesAudienceValidationPass()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('session')
            ->andReturn($session = m::mock(SessionContract::class));
        $session->allows('has')->with('nonce')->andReturns(true);
        $session->allows('get')->with('nonce')->andReturns('test-nonce');

        $provider = new OpenIdTestProviderStub(
            $request,
            'original_id',
            'client_secret',
            'redirect'
        );
        $provider->http = m::mock(Client::class);
        $provider->http->allows('get')->with('http://base.url/.well-known/openid-configuration')
            ->andReturns(new Response(
                body: json_encode(['issuer' => 'http://base.url'])
            ));

        $provider->setConfig(['client_id' => 'tenant_id']);

        $method = new ReflectionMethod($provider, 'validateOIDCPayload');

        // Should pass — aud matches overridden client_id
        $method->invoke($provider, [
            'nonce' => 'test-nonce',
            'aud' => 'tenant_id',
            'iss' => 'http://base.url',
        ]);

        $this->assertTrue(true);
    }

    public function testSetConfigOverridesAudienceValidationFail()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('session')
            ->andReturn($session = m::mock(SessionContract::class));
        $session->allows('has')->with('nonce')->andReturns(true);
        $session->allows('get')->with('nonce')->andReturns('test-nonce');

        $provider = new OpenIdTestProviderStub(
            $request,
            'original_id',
            'client_secret',
            'redirect'
        );

        $provider->setConfig(['client_id' => 'tenant_id']);

        $method = new ReflectionMethod($provider, 'validateOIDCPayload');

        // Should fail — aud matches the original constructor client_id, not the override
        $this->expectException(InvalidAudienceException::class);

        $method->invoke($provider, [
            'nonce' => 'test-nonce',
            'aud' => 'original_id',
            'iss' => 'http://base.url',
        ]);
    }

    public function testOidcJwksRefreshesWhenTokenKidIsMissingFromCachedKeys()
    {
        $oldKey = $this->createRsaKeyPair('old-key');
        $newKey = $this->createRsaKeyPair('new-key');
        $provider = $this->createVerifyingProvider();

        $this->expectOpenIdConfigRequests($provider->http, 2);
        $this->expectJwksRequests($provider->http, [
            $this->jwks($oldKey),
            $this->jwks($newKey),
        ]);

        $user = $provider->verifyToken($this->createSignedToken($newKey));

        $this->assertSame('foo', $user['sub']);
    }

    public function testOidcJwksRemainCachedWhenTokenKidIsPresent()
    {
        $key = $this->createRsaKeyPair('current-key');
        $provider = $this->createVerifyingProvider();

        $this->expectOpenIdConfigRequests($provider->http, 1);
        $this->expectJwksRequests($provider->http, [
            $this->jwks($key),
        ]);

        $firstUser = $provider->verifyToken($this->createSignedToken($key));
        $secondUser = $provider->verifyToken($this->createSignedToken($key));

        $this->assertSame('foo', $firstUser['sub']);
        $this->assertSame('foo', $secondUser['sub']);
    }

    public function testOidcJwksDoesNotRefreshForTokenWithoutKid()
    {
        $key = $this->createRsaKeyPair('current-key');
        $provider = $this->createVerifyingProvider();

        $this->expectOpenIdConfigRequests($provider->http, 1);
        $this->expectJwksRequests($provider->http, [
            $this->jwks($key),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('"kid" empty');

        $provider->verifyToken($this->createSignedToken($key, includeKid: false));
    }

    public function testOidcJwksRefreshCooldownPreventsRepeatedUnknownKidFetches()
    {
        $oldKey = $this->createRsaKeyPair('old-key');
        $newKey = $this->createRsaKeyPair('new-key');
        $provider = $this->createVerifyingProvider();
        $provider->setJwksRefreshCooldownSeconds(60);

        $this->expectOpenIdConfigRequests($provider->http, 2);
        $this->expectJwksRequests($provider->http, [
            $this->jwks($oldKey),
            $this->jwks($oldKey),
        ]);

        $failures = 0;
        $token = $this->createSignedToken($newKey);

        for ($i = 0; $i < 2; ++$i) {
            try {
                $provider->verifyToken($token);
            } catch (UnexpectedValueException $e) {
                $this->assertStringContainsString('"kid" invalid', $e->getMessage());
                ++$failures;
            }
        }

        $this->assertSame(2, $failures);
    }

    public function testOidcJwksRefreshesWhenCachedKeyMaterialIsStale()
    {
        $oldKey = $this->createRsaKeyPair('shared-key');
        $newKey = $this->createRsaKeyPair('shared-key');
        $provider = $this->createVerifyingProvider();

        $this->expectOpenIdConfigRequests($provider->http, 2);
        $this->expectJwksRequests($provider->http, [
            $this->jwks($oldKey),
            $this->jwks($newKey),
        ]);

        $user = $provider->verifyToken($this->createSignedToken($newKey));

        $this->assertSame('foo', $user['sub']);
    }

    private function createVerifyingProvider(): VerifyingOpenIdTestProviderStub
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('session')
            ->andReturn($session = m::mock(SessionContract::class));
        $session->allows('has')->with('nonce')->andReturns(true);
        $session->allows('get')->with('nonce')->andReturns('nonce');

        $provider = new VerifyingOpenIdTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->http = m::mock(Client::class);

        return $provider;
    }

    private function expectOpenIdConfigRequests(Client $http, int $times): void
    {
        $http->shouldReceive('get')
            ->with('http://base.url/.well-known/openid-configuration')
            ->times($times)
            ->andReturn(new Response(
                body: json_encode([
                    'issuer' => 'http://base.url',
                    'token_endpoint' => 'http://token.url',
                    'jwks_uri' => 'http://jwks.url',
                ])
            ));
    }

    private function expectJwksRequests(Client $http, array $jwksResponses): void
    {
        $http->shouldReceive('get')
            ->with('http://jwks.url')
            ->times(count($jwksResponses))
            ->andReturn(...array_map(
                fn (array $jwks): Response => new Response(body: json_encode($jwks)),
                $jwksResponses
            ));
    }

    private function createSignedToken(array $key, bool $includeKid = true): string
    {
        return JWT::encode([
            'iss' => 'http://base.url',
            'sub' => 'foo',
            'aud' => 'client_id',
            'nonce' => 'nonce',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $key['private'], 'RS256', $includeKid ? $key['kid'] : null);
    }

    private function createRsaKeyPair(string $kid): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            $this->fail('Unable to generate RSA key pair for OIDC test.');
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
        return [
            'keys' => [
                $key['jwk'],
            ],
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
