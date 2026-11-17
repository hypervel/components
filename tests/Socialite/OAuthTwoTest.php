<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\Exceptions\InvalidCodeException;
use Hypervel\Socialite\Two\InvalidStateException;
use Hypervel\Socialite\Two\Token;
use Hypervel\Socialite\Two\User;
use Hypervel\Support\Str;
use Hypervel\Tests\Socialite\Fixtures\FacebookTestProviderStub;
use Hypervel\Tests\Socialite\Fixtures\GoogleTestProviderStub;
use Hypervel\Tests\Socialite\Fixtures\OAuthTwoTestProviderStub;
use Hypervel\Tests\Socialite\Fixtures\OAuthTwoWithConfigTestProviderStub;
use Hypervel\Tests\Socialite\Fixtures\OAuthTwoWithPKCETestProviderStub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use SensitiveParameter;
use Swoole\Coroutine\Channel;
use TypeError;

class OAuthTwoTest extends TestCase
{
    use VerifiesDoubles;

    public function testRedirectGeneratesTheProperRedirectResponseWithoutPKCE(): void
    {
        $request = Double::for(Request::class, override: true)->strict();
        $request->expects('session')
            ->returns($session = Double::for(SessionContract::class)->strict());

        $state = null;
        $closure = function ($name, $stateInput) use (&$state) {
            if ($name === 'state') {
                $state = $stateInput;

                return true;
            }

            return false;
        };

        $session->expects('put')->with(Argument::all($closure));
        $provider = new OAuthTwoTestProviderStub(
            $request->instance(),
            'client_id',
            'client_secret',
            'redirect'
        );

        $response = $provider->redirect();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(
            "http://auth.url?client_id=client_id&redirect_uri=redirect&scope=&response_type=code&state={$state}",
            $response->getTargetUrl()
        );
    }

    public function testDirectProviderRedirectClosureIsResolvedOncePerExecution(): void
    {
        $resolutions = 0;
        $provider = new OAuthTwoTestProviderStub(
            new Request,
            'client_id',
            'client_secret',
            function () use (&$resolutions): string {
                ++$resolutions;

                return 'https://tenant.example.com/callback';
            },
        );
        $provider->stateless();

        $this->assertStringContainsString(
            'redirect_uri=https%3A%2F%2Ftenant.example.com%2Fcallback',
            $provider->redirect()->getTargetUrl(),
        );
        $provider->redirect();

        $this->assertSame(1, $resolutions);
    }

    public function testRedirectFormatterUsesCurrentConfigAndSetConfigInvalidatesTheResolvedUrl(): void
    {
        $resolutions = 0;
        $redirectResolutions = 0;
        $provider = new OAuthTwoTestProviderStub(
            new Request,
            'client_id',
            'client_secret',
            '/callback',
        );
        $provider->withConfig([
            'host' => 'https://example.com',
            'redirect' => '/callback',
        ]);
        $provider->withRedirectFormatter(function (array $config) use (&$resolutions): string {
            ++$resolutions;

            return $config['host'] . value($config['redirect']);
        });
        $provider->stateless();

        $this->assertStringContainsString(
            'redirect_uri=https%3A%2F%2Fexample.com%2Fcallback',
            $provider->redirect()->getTargetUrl(),
        );
        $provider->redirect();
        $this->assertSame(1, $resolutions);

        $provider->setConfig(['redirect' => function () use (&$redirectResolutions): string {
            ++$redirectResolutions;

            return '/tenant/callback';
        }]);

        $this->assertStringContainsString(
            'redirect_uri=https%3A%2F%2Fexample.com%2Ftenant%2Fcallback',
            $provider->redirect()->getTargetUrl(),
        );
        $provider->redirect();

        $this->assertSame(2, $resolutions);
        $this->assertSame(1, $redirectResolutions);

        $provider->setConfig(['host' => 'https://tenant.example.com']);

        $this->assertStringContainsString(
            'redirect_uri=https%3A%2F%2Ftenant.example.com%2Ftenant%2Fcallback',
            $provider->redirect()->getTargetUrl(),
        );
        $this->assertSame(3, $resolutions);
        $this->assertSame(2, $redirectResolutions);
    }

    public function testLiteralRedirectOverrideDoesNotEvaluateTheConfiguredClosure(): void
    {
        $resolutions = 0;
        $provider = new OAuthTwoTestProviderStub(
            new Request,
            'client_id',
            'client_secret',
            function () use (&$resolutions): string {
                ++$resolutions;

                return 'https://ignored.example.com/callback';
            },
        );
        $provider->stateless();
        $provider->redirect();
        $provider->redirectUrl('https://literal.example.com/callback');

        $this->assertStringContainsString(
            'redirect_uri=https%3A%2F%2Fliteral.example.com%2Fcallback',
            $provider->redirect()->getTargetUrl(),
        );
        $this->assertSame(1, $resolutions);
    }

    public function testRedirectGeneratesTheProperRedirectResponseWithPKCE(): void
    {
        $request = Double::for(Request::class, override: true)->strict();
        $request->allows('session')
            ->returns($session = Double::for(SessionContract::class)->strict());

        $state = null;
        $codeVerifier = '';
        $sessionPutClosure = function ($name, $value) use (&$state, &$codeVerifier) {
            if ($name === 'state') {
                $state = $value;

                return true;
            }
            if ($name === 'code_verifier') {
                $codeVerifier = $value;

                return true;
            }

            return false;
        };

        $session->expects('put')->times(2)->with(Argument::all($sessionPutClosure));
        $session->expects('get')->with('code_verifier')->resolves(function () use (&$codeVerifier) {
            return $codeVerifier;
        });

        $provider = new OAuthTwoWithPKCETestProviderStub(
            $request->instance(),
            'client_id',
            'client_secret',
            'redirect'
        );

        $response = $provider->redirect();

        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(
            "http://auth.url?client_id=client_id&redirect_uri=redirect&scope=&response_type=code&state={$state}&code_challenge={$codeChallenge}&code_challenge_method=S256",
            $response->getTargetUrl()
        );
    }

    public function testTokenRequestIncludesPKCECodeVerifier(): void
    {
        $request = Double::for(Request::class, override: true)->strict();
        $request->allows('has')
            ->returns(true);
        $request->expects('input')
            ->with('state')
            ->returns(str_repeat('A', 40));
        $request->expects('input')
            ->with('code')
            ->returns('code');
        $request->allows('session')
            ->returns($session = Double::for(SessionContract::class)->strict());

        $codeVerifier = Str::random(32);
        $session->expects('pull')->with('state')->returns(str_repeat('A', 40));
        $session->expects('pull')->with('code_verifier')->returns($codeVerifier);
        $provider = new OAuthTwoWithPKCETestProviderStub(
            $request->instance(),
            'client_id',
            'client_secret',
            'redirect_uri'
        );
        $provider->http = Double::for(Client::class)->strict();
        $provider->http->expects('post')->with('http://token.url', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['grant_type' => 'authorization_code', 'client_id' => 'client_id', 'client_secret' => 'client_secret', 'code' => 'code', 'redirect_uri' => 'redirect_uri', 'code_verifier' => $codeVerifier],
        ])->returns(new Response(body: '{ "access_token" : "access_token", "refresh_token" : "refresh_token", "expires_in" : 3600 }'));
        $user = $provider->user();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('foo', $user->id);
        $this->assertSame('access_token', $user->token);
        $this->assertSame('refresh_token', $user->refreshToken);
        $this->assertSame(3600, $user->expiresIn);
        $this->assertSame($user->id, $provider->user()->id);
    }

    public function testUserReturnsAUserInstanceForTheAuthenticatedRequest(): void
    {
        $request = Double::for(Request::class, override: true)->strict();
        $request->allows('session')
            ->returns($session = Double::for(SessionContract::class)->strict());
        $request->allows('has')
            ->returns(true);
        $request->expects('input')
            ->with('state')
            ->returns(str_repeat('A', 40));
        $request->expects('input')
            ->with('code')
            ->returns('code');

        $session->expects('pull')->with('state')->returns(str_repeat('A', 40));
        $provider = new OAuthTwoTestProviderStub(
            $request->instance(),
            'client_id',
            'client_secret',
            'redirect_uri'
        );
        $provider->http = Double::for(Client::class)->strict();
        $provider->http->expects('post')->with('http://token.url', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['grant_type' => 'authorization_code', 'client_id' => 'client_id', 'client_secret' => 'client_secret', 'code' => 'code', 'redirect_uri' => 'redirect_uri'],
        ])->returns(new Response(body: '{ "access_token" : "access_token", "refresh_token" : "refresh_token", "expires_in" : 3600 }'));
        $user = $provider->user();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('foo', $user->id);
        $this->assertSame('access_token', $user->token);
        $this->assertSame('refresh_token', $user->refreshToken);
        $this->assertSame(3600, $user->expiresIn);
        $this->assertSame([
            'access_token' => 'access_token',
            'refresh_token' => 'refresh_token',
            'expires_in' => 3600,
        ], $user->accessTokenResponseBody);
        $this->assertSame($user->id, $provider->user()->id);
    }

    public function testUserReturnsAUserInstanceForTheAuthenticatedFacebookRequest(): void
    {
        $request = Double::for(Request::class, override: true)->strict();
        $request->allows('session')
            ->returns($session = Double::for(SessionContract::class)->strict());
        $request->allows('has')
            ->returns(true);
        $request->expects('input')
            ->with('state')
            ->returns(str_repeat('A', 40));
        $request->expects('input')
            ->with('code')
            ->returns('code');
        $session->expects('pull')->with('state')->returns(str_repeat('A', 40));
        $provider = new FacebookTestProviderStub(
            $request->instance(),
            'client_id',
            'client_secret',
            'redirect_uri'
        );
        $provider->http = Double::for(Client::class)->strict();
        $provider->http->expects('post')->with('https://graph.facebook.com/v23.0/oauth/access_token', [
            'form_params' => ['grant_type' => 'authorization_code', 'client_id' => 'client_id', 'client_secret' => 'client_secret', 'code' => 'code', 'redirect_uri' => 'redirect_uri'],
        ])->returns(new Response(body: json_encode(['access_token' => 'access_token', 'expires' => 5183085])));
        $user = $provider->user();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('foo', $user->id);
        $this->assertSame('access_token', $user->token);
        $this->assertNull($user->refreshToken);
        $this->assertSame(5183085, $user->expiresIn);
        $this->assertSame($user->id, $provider->user()->id);
    }

    public function testExceptionIsThrownIfStateIsInvalid(): void
    {
        $this->expectException(InvalidStateException::class);

        $request = Double::for(Request::class, override: true)->strict();
        $request->allows('session')
            ->returns($session = Double::for(SessionContract::class)->strict());
        $request->allows('has')
            ->returns(true);
        $request->expects('input')
            ->with('state')
            ->returns(str_repeat('B', 40));

        $session->expects('pull')->with('state')->returns(str_repeat('A', 40));
        $provider = new OAuthTwoTestProviderStub(
            $request->instance(),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->user();
    }

    public function testExceptionIsThrownIfStateIsNotAString(): void
    {
        $this->expectException(InvalidStateException::class);

        $request = Double::for(Request::class, override: true)->strict();
        $request->allows('session')
            ->returns($session = Double::for(SessionContract::class)->strict());
        $request->expects('input')
            ->with('state')
            ->returns([str_repeat('A', 40)]);

        $session->expects('pull')->with('state')->returns(str_repeat('A', 40));
        $provider = new OAuthTwoTestProviderStub(
            $request->instance(),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->user();
    }

    public function testExceptionIsThrownIfStateIsNotSet(): void
    {
        $this->expectException(InvalidStateException::class);

        $request = Double::for(Request::class, override: true)->strict();
        $request->allows('session')
            ->returns($session = Double::for(SessionContract::class)->strict());
        $session->expects('pull')->with('state');
        $provider = new OAuthTwoTestProviderStub(
            $request->instance(),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->user();
    }

    #[DataProvider('invalidAuthorizationCodeProvider')]
    public function testExceptionIsThrownIfAuthorizationCodeIsInvalid(mixed $code): void
    {
        $request = Double::for(Request::class, override: true)->strict();
        $request->allows('session')
            ->returns($session = Double::for(SessionContract::class)->strict());
        $request->expects('input')
            ->with('state')
            ->returns(str_repeat('A', 40));
        $request->expects('input')
            ->with('code')
            ->returns($code);

        $session->expects('pull')->with('state')->returns(str_repeat('A', 40));
        $provider = new OAuthTwoTestProviderStub(
            $request->instance(),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->http = Double::for(Client::class)->strict();
        $provider->http->expects('post')->never();

        $this->expectException(InvalidCodeException::class);
        $this->expectExceptionMessageIs('The authorization code is missing or invalid.');

        $provider->user();
    }

    /**
     * Provide invalid authorization codes.
     */
    public static function invalidAuthorizationCodeProvider(): array
    {
        return [
            'declined authorization' => [null],
            'array' => [['code']],
            'empty string' => [''],
        ];
    }

    public function testUserRefreshesToken(): void
    {
        $request = new Request;
        $provider = new OAuthTwoTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect_uri'
        );
        $provider->http = Double::for(Client::class)->strict();
        $provider->http->expects('post')->with('http://token.url', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['grant_type' => 'refresh_token', 'refresh_token' => 'refresh_token', 'client_id' => 'client_id', 'client_secret' => 'client_secret'],
        ])->returns(new Response(body: '{ "access_token" : "access_token", "refresh_token" : "refresh_token", "expires_in" : 3600, "scope" : "scope1,scope2" }'));
        $token = $provider->refreshToken('refresh_token');

        $this->assertInstanceOf(Token::class, $token);
        $this->assertSame('access_token', $token->token);
        $this->assertSame('refresh_token', $token->refreshToken);
        $this->assertSame(3600, $token->expiresIn);
        $this->assertSame(['scope1', 'scope2'], $token->approvedScopes);
    }

    public function testUserRefreshesGoogleToken(): void
    {
        $request = new Request;
        $provider = new GoogleTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect_uri'
        );
        $provider->http = Double::for(Client::class)->strict();
        $provider->http->expects('post')->with('http://token.url', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['grant_type' => 'refresh_token', 'refresh_token' => 'refresh_token', 'client_id' => 'client_id', 'client_secret' => 'client_secret'],
        ])->returns(new Response(body: '{ "access_token" : "access_token", "expires_in" : 3600, "scope" : "scope1 scope2" }'));
        $token = $provider->refreshToken('refresh_token');

        $this->assertInstanceOf(Token::class, $token);
        $this->assertSame('access_token', $token->token);
        $this->assertSame('refresh_token', $token->refreshToken);
        $this->assertSame(3600, $token->expiresIn);
        $this->assertSame(['scope1', 'scope2'], $token->approvedScopes);
    }

    public function testTokenResponseParsersNormalizeProviderValues(): void
    {
        $provider = new OAuthTwoTestProviderStub(
            new Request,
            'client_id',
            'client_secret',
            'redirect'
        );

        $this->assertSame('access-token', $provider->parseProviderAccessToken(['access_token' => 'access-token']));
        $this->assertNull($provider->parseProviderRefreshToken([]));
        $this->assertSame('refresh-token', $provider->parseProviderRefreshToken(['refresh_token' => 'refresh-token']));
        $this->assertSame([], $provider->parseProviderApprovedScopes([]));
        $this->assertSame([], $provider->parseProviderApprovedScopes(['scope' => '']));
        $this->assertSame(['read', 'write'], $provider->parseProviderApprovedScopes(['scope' => 'read,write']));
        $this->assertSame(['read', 'write'], $provider->parseProviderApprovedScopes(['scope' => ['read', 'write']]));
    }

    #[DataProvider('expiresInProvider')]
    public function testExpiresInParserAcceptsNonNegativeIntegers(array $response, ?int $expected): void
    {
        $provider = new OAuthTwoTestProviderStub(
            new Request,
            'client_id',
            'client_secret',
            'redirect'
        );

        $this->assertSame($expected, $provider->parseProviderExpiresIn($response));
    }

    /**
     * Provide token lifetime values.
     */
    public static function expiresInProvider(): array
    {
        return [
            'missing' => [[], null],
            'zero integer' => [['expires_in' => 0], 0],
            'positive integer' => [['expires_in' => 600], 600],
            'negative integer' => [['expires_in' => -1], null],
            'zero string' => [['expires_in' => '0'], 0],
            'positive digit string' => [['expires_in' => '600'], 600],
            'zero-padded string' => [['expires_in' => '0600'], 600],
            'negative string' => [['expires_in' => '-1'], null],
            'decimal string' => [['expires_in' => '1.5'], null],
            'integer maximum' => [['expires_in' => (string) PHP_INT_MAX], PHP_INT_MAX],
            'integer overflow' => [['expires_in' => (string) PHP_INT_MAX . '0'], null],
            'float' => [['expires_in' => 600.0], null],
        ];
    }

    public function testMissingAccessTokenFailsAtTheParserBoundary(): void
    {
        $provider = new OAuthTwoTestProviderStub(
            new Request,
            'client_id',
            'client_secret',
            'redirect'
        );

        $this->expectException(TypeError::class);

        $provider->parseProviderAccessToken([]);
    }

    public function testWholeTokenResponseCanMapAndPopulateTheUser(): void
    {
        $request = Double::for(Request::class, override: true)->strict();
        $request->expects('input')->with('code')->returns('code');

        $provider = new OAuthTwoWholeResponseTestProviderStub(
            $request->instance(),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->stateless();
        $provider->http = Double::for(Client::class)->strict();
        $provider->http->expects('post')->returns(new Response(body: json_encode([
            'access_token' => 'access-token',
            'profile_id' => 'response-user',
        ])));

        $user = $provider->user();

        $this->assertSame('response-user', $user->id);
        $this->assertSame([
            'access_token' => 'access-token',
            'profile_id' => 'response-user',
        ], $user->accessTokenResponseBody);

        $userFromToken = $provider->userFromToken('known-token');

        $this->assertSame([], $userFromToken->accessTokenResponseBody);
    }

    public function testFailedUserDecorationDoesNotCachePartialUser(): void
    {
        $request = Double::for(Request::class, override: true)->strict();
        $request->expects('input')->with('code')->returns('code');

        $provider = new OAuthTwoWholeResponseTestProviderStub(
            $request->instance(),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->stateless();
        $provider->http = Double::for(Client::class)->strict();
        $provider->http->expects('post')->returns(new Response(body: '{"profile_id":"response-user"}'));

        try {
            $provider->user();
            $this->fail('Expected token parsing to fail.');
        } catch (TypeError) {
        }

        $this->assertNull($provider->getProviderUser());
    }

    public function testSetConfigOverridesCredentialsInRedirect(): void
    {
        $request = new Request;

        $provider = new OAuthTwoTestProviderStub(
            $request,
            'original_id',
            'original_secret',
            'original_redirect'
        );
        $provider->stateless();
        $provider->setConfig([
            'client_id' => 'tenant_id',
            'client_secret' => 'tenant_secret',
            'redirect' => 'tenant_redirect',
        ]);

        $response = $provider->redirect();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('client_id=tenant_id', $response->getTargetUrl());
        $this->assertStringContainsString('redirect_uri=tenant_redirect', $response->getTargetUrl());
        $this->assertStringNotContainsString('original_id', $response->getTargetUrl());
        $this->assertStringNotContainsString('original_redirect', $response->getTargetUrl());
    }

    public function testSetConfigOverridesCredentialsInTokenRequest(): void
    {
        $request = Double::for(Request::class, override: true)->strict();
        $request->allows('session')
            ->returns($session = Double::for(SessionContract::class)->strict());
        $request->allows('has')
            ->returns(true);
        $request->expects('input')
            ->with('state')
            ->returns(str_repeat('A', 40));
        $request->expects('input')
            ->with('code')
            ->returns('code');

        $session->expects('pull')->with('state')->returns(str_repeat('A', 40));

        $provider = new OAuthTwoTestProviderStub(
            $request->instance(),
            'original_id',
            'original_secret',
            'original_redirect'
        );
        $provider->setConfig([
            'client_id' => 'tenant_id',
            'client_secret' => 'tenant_secret',
            'redirect' => 'tenant_redirect',
        ]);
        $provider->http = Double::for(Client::class)->strict();
        $provider->http->expects('post')->with('http://token.url', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => [
                'grant_type' => 'authorization_code',
                'client_id' => 'tenant_id',
                'client_secret' => 'tenant_secret',
                'code' => 'code',
                'redirect_uri' => 'tenant_redirect',
            ],
        ])->returns(new Response(body: '{ "access_token" : "access_token", "refresh_token" : "refresh_token", "expires_in" : 3600 }'));

        $user = $provider->user();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('foo', $user->id);
    }

    public function testSetConfigOverridesCredentialsInRefreshToken(): void
    {
        $request = new Request;

        $provider = new OAuthTwoTestProviderStub(
            $request,
            'original_id',
            'original_secret',
            'original_redirect'
        );
        $provider->setConfig([
            'client_id' => 'tenant_id',
            'client_secret' => 'tenant_secret',
        ]);
        $provider->http = Double::for(Client::class)->strict();
        $provider->http->expects('post')->with('http://token.url', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => 'refresh_token',
                'client_id' => 'tenant_id',
                'client_secret' => 'tenant_secret',
            ],
        ])->returns(new Response(body: '{ "access_token" : "access_token", "refresh_token" : "new_refresh_token", "expires_in" : 3600, "scope" : "scope1,scope2" }'));

        $token = $provider->refreshToken('refresh_token');

        $this->assertInstanceOf(Token::class, $token);
        $this->assertSame('access_token', $token->token);
    }

    public function testSetConfigPartialOverridePreservesDefaults(): void
    {
        $request = new Request;

        $provider = new OAuthTwoTestProviderStub(
            $request,
            'original_id',
            'original_secret',
            'original_redirect'
        );
        $provider->stateless();
        $provider->setConfig([
            'client_id' => 'tenant_id',
        ]);

        $response = $provider->redirect();

        $this->assertStringContainsString('client_id=tenant_id', $response->getTargetUrl());
        $this->assertStringContainsString('redirect_uri=original_redirect', $response->getTargetUrl());
    }

    public function testGetConfigReturnsAdditionalKeys(): void
    {
        $request = new Request;

        $provider = new OAuthTwoWithConfigTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->setConfig([
            'client_id' => 'client_id',
            'client_secret' => 'client_secret',
            'redirect' => 'redirect',
            'base_url' => 'https://custom.example.com',
        ]);

        $this->assertSame('https://custom.example.com', $provider->getProviderConfig('base_url'));
        $this->assertSame('default_value', $provider->getProviderConfig('missing_key', 'default_value'));
        $this->assertNull($provider->getProviderConfig('missing_key'));
    }

    public function testWithConfigBaselineSurvivesAcrossCoroutines(): void
    {
        $request = new Request;

        $provider = new OAuthTwoWithConfigTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->withConfig([
            'client_id' => 'client_id',
            'client_secret' => 'client_secret',
            'redirect' => 'redirect',
            'base_url' => 'https://auth.example.com',
        ]);

        $childBaseUrl = null;
        $childBaseUrlAfterPartialOverride = null;
        $channel = new Channel(1);

        Coroutine::create(function () use ($provider, &$childBaseUrl, &$childBaseUrlAfterPartialOverride, $channel) {
            // Fresh coroutine with no setConfig — should fall back to baseline
            $childBaseUrl = $provider->getProviderConfig('base_url');

            // Partial override should preserve baseline keys
            $provider->setConfig(['client_id' => 'tenant_id']);
            $childBaseUrlAfterPartialOverride = $provider->getProviderConfig('base_url');

            $channel->push(true);
        });

        $channel->pop(1.0);

        $this->assertSame('https://auth.example.com', $childBaseUrl);
        $this->assertSame('https://auth.example.com', $childBaseUrlAfterPartialOverride);

        // Parent coroutine should also still see baseline
        $this->assertSame('https://auth.example.com', $provider->getProviderConfig('base_url'));
    }

    public function testSetConfigIsIsolatedPerCoroutine(): void
    {
        $request = new Request;

        $provider = new OAuthTwoTestProviderStub(
            $request,
            'base_id',
            'base_secret',
            'base_redirect'
        );
        $provider->stateless();

        // Parent coroutine sets tenant_a
        $provider->setConfig(['client_id' => 'tenant_a']);

        $childUrl = null;
        $fallbackUrl = null;
        $channel = new Channel(2);

        // Child coroutine sets tenant_b on the SAME provider instance
        Coroutine::create(function () use ($provider, &$childUrl, $channel) {
            $provider->stateless();
            $provider->setConfig(['client_id' => 'tenant_b']);
            $childUrl = $provider->redirect()->getTargetUrl();
            $channel->push(true);
        });

        // Third coroutine without any setConfig — should fall back to constructor default
        Coroutine::create(function () use ($provider, &$fallbackUrl, $channel) {
            $provider->stateless();
            $fallbackUrl = $provider->redirect()->getTargetUrl();
            $channel->push(true);
        });

        $channel->pop(1.0);
        $channel->pop(1.0);

        $parentUrl = $provider->redirect()->getTargetUrl();

        $this->assertStringContainsString('client_id=tenant_a', $parentUrl);
        $this->assertStringContainsString('client_id=tenant_b', $childUrl);
        $this->assertStringContainsString('client_id=base_id', $fallbackUrl);
    }
}

class OAuthTwoWholeResponseTestProviderStub extends OAuthTwoTestProviderStub
{
    /**
     * Map the profile identifier from the full token response.
     */
    protected function getUserByTokenResponse(#[SensitiveParameter] array $response): array
    {
        return ['id' => $response['profile_id']];
    }
}
