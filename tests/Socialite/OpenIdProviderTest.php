<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\Exceptions\ConfigurationFetchingException;
use Hypervel\Socialite\Two\Exceptions\InvalidAudienceException;
use Hypervel\Socialite\Two\Exceptions\InvalidNonceException;
use Hypervel\Socialite\Two\Exceptions\InvalidUserInfoUrlException;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\Socialite\Fixtures\CreatesJwksFixtures;
use Hypervel\Tests\Socialite\Fixtures\OpenIdTestProviderStub;
use Hypervel\Tests\Socialite\Fixtures\VerifyingOpenIdTestProviderStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use ReflectionMethod;
use RuntimeException;
use TypeError;
use UnexpectedValueException;

class OpenIdProviderTest extends TestCase
{
    use CreatesJwksFixtures;

    public function testRedirectGeneratesTheProperRedirectResponseWithoutPKCE(): void
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

    public function testUserReturnsAUserInstanceForTheAuthenticatedRequest(): void
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
        $session->expects('pull')->with('nonce')->andReturns('nonce');
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
        $this->assertSame([
            'access_token' => 'access_token',
            'id_token' => 'id_token',
            'refresh_token' => 'refresh_token',
            'expires_in' => 3600,
        ], $user->accessTokenResponseBody);
        $this->assertSame($user->id, $provider->user()->id);
    }

    public function testSetConfigOverridesAudienceValidationPass(): void
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('session')
            ->andReturn($session = m::mock(SessionContract::class));
        $session->expects('pull')->with('nonce')->andReturns('test-nonce');

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

    public function testSetConfigOverridesAudienceValidationFail(): void
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('session')
            ->andReturn($session = m::mock(SessionContract::class));
        $session->expects('pull')->with('nonce')->andReturns('test-nonce');

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

    public function testTrustedAudiencesAcceptScalarConfigurationAndIgnoreAzp(): void
    {
        $request = m::mock(Request::class);
        $request->expects('session')->andReturn($session = m::mock(SessionContract::class));
        $session->expects('pull')->with('nonce')->andReturn('nonce');

        $provider = new OpenIdTestProviderStub($request, 'client_id', 'client_secret', 'redirect');
        $provider->withConfig(['trusted_audiences' => 'trusted-api']);
        $provider->http = m::mock(Client::class);
        $provider->http->expects('get')
            ->with('http://base.url/.well-known/openid-configuration')
            ->andReturn(new Response(body: json_encode(['issuer' => 'http://base.url'])));

        $provider->validateProviderPayload([
            'nonce' => 'nonce',
            'aud' => ['client_id', 'trusted-api'],
            'azp' => 'another-party',
            'iss' => 'http://base.url',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testAudienceRejectsUntrustedAndNonStringEntries(): void
    {
        $request = m::mock(Request::class);
        $request->expects('session')->andReturn($session = m::mock(SessionContract::class));
        $session->expects('pull')->with('nonce')->andReturn('nonce');

        $provider = new OpenIdTestProviderStub($request, 'client_id', 'client_secret', 'redirect');

        $this->expectException(InvalidAudienceException::class);

        $provider->validateProviderPayload([
            'nonce' => 'nonce',
            'aud' => ['client_id', 123],
            'iss' => 'http://base.url',
        ]);
    }

    public function testNonceIsConsumedAfterOneValidation(): void
    {
        $request = m::mock(Request::class);
        $request->expects('session')->twice()->andReturn($session = m::mock(SessionContract::class));
        $session->expects('pull')->with('nonce')->twice()->andReturn('nonce', null);

        $provider = new OpenIdTestProviderStub($request, 'client_id', 'client_secret', 'redirect');
        $provider->http = m::mock(Client::class);
        $provider->http->expects('get')
            ->with('http://base.url/.well-known/openid-configuration')
            ->andReturn(new Response(body: json_encode(['issuer' => 'http://base.url'])));

        $payload = [
            'nonce' => 'nonce',
            'aud' => 'client_id',
            'iss' => 'http://base.url',
        ];

        $provider->validateProviderPayload($payload);

        $this->expectException(InvalidNonceException::class);

        $provider->validateProviderPayload($payload);
    }

    public function testUserInfoUsesBearerAuthorizationWithoutTokenQuery(): void
    {
        $provider = new OpenIdTestProviderStub(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->http = m::mock(Client::class);
        $provider->http->expects('get')
            ->with('http://base.url/.well-known/openid-configuration')
            ->andReturn(new Response(body: json_encode([
                'userinfo_endpoint' => 'http://userinfo.url',
            ])));
        $provider->http->expects('get')->with('http://userinfo.url', [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer access-token',
            ],
        ])->andReturn(new Response(body: '{"sub":"foo"}'));

        $this->assertSame(['sub' => 'foo'], $provider->getProviderUserByToken('access-token'));
    }

    public function testMissingIdTokenFailsAtTheRequiredResponseBoundary(): void
    {
        $provider = new OpenIdTestProviderStub(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect'
        );

        $warning = null;
        set_error_handler(function (int $severity, string $message) use (&$warning): bool {
            if ($severity !== E_WARNING) {
                return false;
            }

            $warning = $message;

            return true;
        });

        try {
            $provider->getProviderUserByTokenResponse(['access_token' => 'access-token']);
            $this->fail('Expected the missing ID token to fail.');
        } catch (TypeError) {
            $this->assertSame('Undefined array key "id_token"', $warning);
        } finally {
            restore_error_handler();
        }
    }

    public function testDiscoveryCacheIsKeyedByTheExactConfigurationUrl(): void
    {
        $provider = new OpenIdTestProviderStub(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->http = m::mock(Client::class);
        $provider->http->expects('get')
            ->with('https://tenant-a.example/.well-known/openid-configuration')
            ->once()
            ->andReturn(new Response(body: '{"issuer":"tenant-a"}'));
        $provider->http->expects('get')
            ->with('https://tenant-b.example/.well-known/openid-configuration')
            ->once()
            ->andReturn(new Response(body: '{"issuer":"tenant-b"}'));

        $provider->setConfig(['base_url' => 'https://tenant-a.example']);
        $this->assertSame('tenant-a', $provider->getProviderOpenIdConfig()['issuer']);
        $this->assertSame('tenant-a', $provider->getProviderOpenIdConfig()['issuer']);

        $provider->setConfig(['base_url' => 'https://tenant-b.example']);
        $this->assertSame('tenant-b', $provider->getProviderOpenIdConfig()['issuer']);
    }

    public function testDiscoveryRefreshReachesTheNetwork(): void
    {
        $provider = new OpenIdTestProviderStub(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->http = m::mock(Client::class);
        $provider->http->expects('get')
            ->with('http://base.url/.well-known/openid-configuration')
            ->twice()
            ->andReturn(
                new Response(body: '{"issuer":"first"}'),
                new Response(body: '{"issuer":"refreshed"}'),
            );

        $this->assertSame('first', $provider->getProviderOpenIdConfig()['issuer']);
        $this->assertSame('first', $provider->getProviderOpenIdConfig()['issuer']);
        $this->assertSame('refreshed', $provider->getProviderOpenIdConfig(refresh: true)['issuer']);
    }

    public function testFailedDiscoveryDoesNotReplaceThePreviousExactUrlEntry(): void
    {
        $provider = new OpenIdTestProviderStub(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->http = m::mock(Client::class);
        $provider->http->expects('get')
            ->with('https://tenant-a.example/.well-known/openid-configuration')
            ->once()
            ->andReturn(new Response(body: '{"issuer":"tenant-a"}'));
        $provider->http->expects('get')
            ->with('https://tenant-b.example/.well-known/openid-configuration')
            ->once()
            ->andThrow($failure = new RuntimeException('Discovery unavailable.'));

        $provider->setConfig(['base_url' => 'https://tenant-a.example']);
        $this->assertSame('tenant-a', $provider->getProviderOpenIdConfig()['issuer']);

        $provider->setConfig(['base_url' => 'https://tenant-b.example']);

        try {
            $provider->getProviderOpenIdConfig();
            $this->fail('Expected discovery to fail.');
        } catch (ConfigurationFetchingException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
        }

        $provider->setConfig(['base_url' => 'https://tenant-a.example']);
        $this->assertSame('tenant-a', $provider->getProviderOpenIdConfig()['issuer']);
    }

    public function testDiscoveryRejectsJsonWithoutNamedFields(): void
    {
        $provider = new OpenIdTestProviderStub(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->http = m::mock(Client::class);
        $provider->http->expects('get')->andReturn(new Response(body: '{}'));

        try {
            $provider->getProviderOpenIdConfig();
            $this->fail('Expected discovery metadata to be rejected.');
        } catch (ConfigurationFetchingException $exception) {
            $this->assertInstanceOf(UnexpectedValueException::class, $exception->getPrevious());
        }
    }

    public function testOidcOperationalExceptionsUseRuntimeTaxonomy(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new ConfigurationFetchingException);
        $this->assertInstanceOf(RuntimeException::class, new InvalidUserInfoUrlException);
    }

    public function testOidcValidationDoesNotRequireNonceWhenDisabled(): void
    {
        $key = $this->createRsaKeyPair('nonce-disabled-key');
        $provider = $this->createVerifyingProvider();

        $this->expectOpenIdConfigRequests($provider->http, 1);
        $this->expectJwksRequests($provider->http, [$this->jwks($key)]);

        $this->assertSame('foo', $provider->verifyToken($this->createSignedToken($key))['sub']);
    }

    public function testOidcJwksRefreshesWhenTokenKidIsMissingFromCachedKeys(): void
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

    public function testOidcJwksRemainCachedWhenTokenKidIsPresent(): void
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

    public function testOidcJwksDoesNotRefreshForTokenWithoutKid(): void
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

    public function testOidcJwksRefreshCooldownPreventsRepeatedUnknownKidFetches(): void
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

    public function testOidcJwksRefreshesWhenCachedKeyMaterialIsStale(): void
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

    public function testOidcJwksHonorsZeroPaddedMaxAgeAndRefetchesWhenImmediatelyStale(): void
    {
        $key = $this->createRsaKeyPair('cache-key');
        $provider = $this->createVerifyingProvider();

        $this->expectOpenIdConfigRequests($provider->http, 1);
        $this->expectJwksRequests($provider->http, [
            new Response(headers: ['Cache-Control' => 'max-age=0000'], body: json_encode($this->jwks($key))),
            new Response(headers: ['Cache-Control' => 'max-age=60'], body: json_encode($this->jwks($key))),
        ]);

        $this->assertSame('foo', $provider->verifyToken($this->createSignedToken($key))['sub']);
        $this->assertSame('foo', $provider->verifyToken($this->createSignedToken($key))['sub']);
    }

    #[DataProvider('immediateStalenessDirectiveProvider')]
    public function testOidcJwksNoCacheDirectivesWinAcrossRepeatedHeaders(string $directive): void
    {
        $key = $this->createRsaKeyPair('no-cache-key-' . strtolower($directive));
        $provider = $this->createVerifyingProvider();

        $this->expectOpenIdConfigRequests($provider->http, 1);
        $this->expectJwksRequests($provider->http, [
            new Response(headers: [
                'Cache-Control' => ['max-age=600', 'private, ' . $directive],
            ], body: json_encode($this->jwks($key))),
            $this->jwks($key),
        ]);

        $token = $this->createSignedToken($key);

        $provider->verifyToken($token);
        $provider->verifyToken($token);

        $this->addToAssertionCount(1);
    }

    public static function immediateStalenessDirectiveProvider(): array
    {
        return [
            'no-cache' => ['NO-CACHE'],
            'no-store' => ['no-store'],
        ];
    }

    public function testOidcJwksUsesTheSmallestRepeatedMaxAge(): void
    {
        $key = $this->createRsaKeyPair('repeated-max-age');
        $provider = $this->createVerifyingProvider();

        $this->expectOpenIdConfigRequests($provider->http, 1);
        $this->expectJwksRequests($provider->http, [
            new Response(headers: [
                'Cache-Control' => ['max-age=600', 'private, MAX-AGE=0'],
            ], body: json_encode($this->jwks($key))),
            $this->jwks($key),
        ]);

        $token = $this->createSignedToken($key);

        $provider->verifyToken($token);
        $provider->verifyToken($token);

        $this->addToAssertionCount(1);
    }

    public function testOidcJwksFallsBackToDefaultTtlForMalformedAndOverflowingMaxAgeValues(): void
    {
        $key = $this->createRsaKeyPair('malformed-max-age');
        $provider = $this->createVerifyingProvider();

        $this->expectOpenIdConfigRequests($provider->http, 1);
        $this->expectJwksRequests($provider->http, [
            new Response(headers: [
                'Cache-Control' => 'max-age=-1, max-age=invalid, max-age=' . PHP_INT_MAX . '0',
            ], body: json_encode($this->jwks($key))),
        ]);

        $token = $this->createSignedToken($key);

        $provider->verifyToken($token);
        $provider->verifyToken($token);

        $this->addToAssertionCount(1);
    }

    public function testOidcJwksUsesDefaultTtlWhenCacheDirectivesAreMissing(): void
    {
        $key = $this->createRsaKeyPair('default-ttl-key');
        $provider = $this->createVerifyingProvider();
        $provider->setJwksDefaultTtlSeconds(0);

        $this->expectOpenIdConfigRequests($provider->http, 1);
        $this->expectJwksRequests($provider->http, [
            $this->jwks($key),
            $this->jwks($key),
        ]);

        $token = $this->createSignedToken($key);

        $this->assertSame('foo', $provider->verifyToken($token)['sub']);
        $this->assertSame('foo', $provider->verifyToken($token)['sub']);
    }

    public function testOidcJwksSwitchesWithTheExactDiscoveryUrl(): void
    {
        $tenantAKey = $this->createRsaKeyPair('tenant-a-key');
        $tenantBKey = $this->createRsaKeyPair('tenant-b-key');
        $provider = $this->createVerifyingProvider();

        $provider->http->expects('get')
            ->with('https://tenant-a.example/.well-known/openid-configuration')
            ->once()
            ->andReturn(new Response(body: json_encode([
                'issuer' => 'tenant-a',
                'jwks_uri' => 'https://tenant-a.example/jwks',
            ])));
        $provider->http->expects('get')
            ->with('https://tenant-b.example/.well-known/openid-configuration')
            ->once()
            ->andReturn(new Response(body: json_encode([
                'issuer' => 'tenant-b',
                'jwks_uri' => 'https://tenant-b.example/jwks',
            ])));
        $provider->http->expects('get')
            ->with('https://tenant-a.example/jwks')
            ->once()
            ->andReturn(new Response(body: json_encode($this->jwks($tenantAKey))));
        $provider->http->expects('get')
            ->with('https://tenant-b.example/jwks')
            ->once()
            ->andReturn(new Response(body: json_encode($this->jwks($tenantBKey))));

        $provider->setConfig(['base_url' => 'https://tenant-a.example']);
        $this->assertSame('foo', $provider->verifyToken($this->createSignedToken($tenantAKey, issuer: 'tenant-a'))['sub']);

        $provider->setConfig(['base_url' => 'https://tenant-b.example']);
        $this->assertSame('foo', $provider->verifyToken($this->createSignedToken($tenantBKey, issuer: 'tenant-b'))['sub']);
    }

    public function testOidcColdJwksFailureRetriesOnTheNextLogin(): void
    {
        $key = $this->createRsaKeyPair('cold-retry-key');
        $provider = $this->createVerifyingProvider();

        $this->expectOpenIdConfigRequests($provider->http, 1);
        $attempt = 0;
        $provider->http->expects('get')
            ->with('http://jwks.url')
            ->twice()
            ->andReturnUsing(function () use (&$attempt, $key): Response {
                if ($attempt++ === 0) {
                    throw new RuntimeException('JWKS unavailable.');
                }

                return new Response(body: json_encode($this->jwks($key)));
            });

        try {
            $provider->verifyToken($this->createSignedToken($key));
            $this->fail('Expected the first JWKS request to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('JWKS unavailable.', $exception->getMessage());
        }

        $this->assertSame('foo', $provider->verifyToken($this->createSignedToken($key))['sub']);
    }

    public function testOidcFailedForcedRefreshIsThrottledWhenCachedKeysRemain(): void
    {
        $oldKey = $this->createRsaKeyPair('throttled-old-key');
        $newKey = $this->createRsaKeyPair('throttled-new-key');
        $provider = $this->createVerifyingProvider();
        $provider->setJwksRefreshCooldownSeconds(60);

        $this->expectOpenIdConfigRequests($provider->http, 2);
        $attempt = 0;
        $provider->http->expects('get')
            ->with('http://jwks.url')
            ->twice()
            ->andReturnUsing(function () use (&$attempt, $oldKey): Response {
                if ($attempt++ === 0) {
                    return new Response(body: json_encode($this->jwks($oldKey)));
                }

                throw new RuntimeException('Refresh failed.');
            });

        $token = $this->createSignedToken($newKey);

        try {
            $provider->verifyToken($token);
            $this->fail('Expected the forced refresh to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Refresh failed.', $exception->getMessage());
        }

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('"kid" invalid');

        $provider->verifyToken($token);
    }

    public function testOidcChangedJwksUrlFailureRetriesWithoutPublishingPartialState(): void
    {
        $oldKey = $this->createRsaKeyPair('changed-url-old-key');
        $newKey = $this->createRsaKeyPair('changed-url-new-key');
        $provider = $this->createVerifyingProvider();

        $provider->http->expects('get')
            ->with('http://base.url/.well-known/openid-configuration')
            ->twice()
            ->andReturn(
                new Response(body: json_encode([
                    'issuer' => 'http://base.url',
                    'jwks_uri' => 'http://old-jwks.url',
                ])),
                new Response(body: json_encode([
                    'issuer' => 'http://base.url',
                    'jwks_uri' => 'http://new-jwks.url',
                ])),
            );
        $provider->http->expects('get')
            ->with('http://old-jwks.url')
            ->once()
            ->andReturn(new Response(body: json_encode($this->jwks($oldKey))));
        $newJwksAttempt = 0;
        $provider->http->expects('get')
            ->with('http://new-jwks.url')
            ->twice()
            ->andReturnUsing(function () use (&$newJwksAttempt, $newKey): Response {
                if ($newJwksAttempt++ === 0) {
                    throw new RuntimeException('New JWKS unavailable.');
                }

                return new Response(body: json_encode($this->jwks($newKey)));
            });

        $token = $this->createSignedToken($newKey);

        try {
            $provider->verifyToken($token);
            $this->fail('Expected the changed JWKS URL to fail once.');
        } catch (RuntimeException $exception) {
            $this->assertSame('New JWKS unavailable.', $exception->getMessage());
        }

        $this->assertSame('foo', $provider->verifyToken($token)['sub']);
    }

    private function createVerifyingProvider(): VerifyingOpenIdTestProviderStub
    {
        $request = m::mock(Request::class);

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
                fn (array|Response $response): Response => $response instanceof Response
                    ? $response
                    : new Response(body: json_encode($response)),
                $jwksResponses
            ));
    }

    private function createSignedToken(
        array $key,
        bool $includeKid = true,
        string $issuer = 'http://base.url',
    ): string {
        return JWT::encode([
            'iss' => $issuer,
            'sub' => 'foo',
            'aud' => 'client_id',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $key['private'], 'RS256', $includeKid ? $key['kid'] : null);
    }
}
