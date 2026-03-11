<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use Hypervel\Context\Context;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\Exceptions\InvalidStateException;
use Hypervel\Socialite\Two\Token;
use Hypervel\Socialite\Two\User;
use Hypervel\Support\Str;
use Hypervel\Tests\Socialite\Fixtures\FacebookTestProviderStub;
use Hypervel\Tests\Socialite\Fixtures\GoogleTestProviderStub;
use Hypervel\Tests\Socialite\Fixtures\OAuthTwoTestProviderStub;
use Hypervel\Tests\Socialite\Fixtures\OAuthTwoWithPKCETestProviderStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 * @coversNothing
 */
class OAuthTwoTest extends TestCase
{
    public function tearDown(): void
    {
        parent::tearDown();

        Context::flush();
    }

    public function testRedirectGeneratesTheProperRedirectResponseWithoutPKCE()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('session')
            ->once()
            ->andReturn($session = m::mock(SessionContract::class));

        $state = null;
        $closure = function ($name, $stateInput) use (&$state) {
            if ($name === 'state') {
                $state = $stateInput;

                return true;
            }

            return false;
        };

        $session->expects('put')->withArgs($closure);
        $provider = new OAuthTwoTestProviderStub(
            $request,
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

    public function testRedirectGeneratesTheProperRedirectResponseWithPKCE()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('session')
            ->andReturn($session = m::mock(SessionContract::class));

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

        $session->expects('put')->twice()->withArgs($sessionPutClosure);
        $session->expects('get')->once()->with('code_verifier')->andReturnUsing(function () use (&$codeVerifier) {
            return $codeVerifier;
        });

        $provider = new OAuthTwoWithPKCETestProviderStub(
            $request,
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

    public function testTokenRequestIncludesPKCECodeVerifier()
    {
        $request = m::mock(Request::class);
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
        $request->shouldReceive('session')
            ->andReturn($session = m::mock(SessionContract::class));

        $codeVerifier = Str::random(32);
        $session->expects('pull')->with('state')->andReturns(str_repeat('A', 40));
        $session->expects('pull')->with('code_verifier')->andReturns($codeVerifier);
        $provider = new OAuthTwoWithPKCETestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect_uri'
        );
        $provider->http = m::mock(Client::class);
        $provider->http->expects('post')->with('http://token.url', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['grant_type' => 'authorization_code', 'client_id' => 'client_id', 'client_secret' => 'client_secret', 'code' => 'code', 'redirect_uri' => 'redirect_uri', 'code_verifier' => $codeVerifier],
        ])->andReturns($response = m::mock(ResponseInterface::class));
        $stream = m::mock(StreamInterface::class);
        $stream->allows('__toString')->andReturns('{ "access_token" : "access_token", "refresh_token" : "refresh_token", "expires_in" : 3600 }');
        $response->expects('getBody')->andReturns($stream);
        $user = $provider->user();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('foo', $user->id);
        $this->assertSame('access_token', $user->token);
        $this->assertSame('refresh_token', $user->refreshToken);
        $this->assertSame(3600, $user->expiresIn);
        $this->assertSame($user->id, $provider->user()->id);
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
        $provider = new OAuthTwoTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect_uri'
        );
        $provider->http = m::mock(Client::class);
        $provider->http->expects('post')->with('http://token.url', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['grant_type' => 'authorization_code', 'client_id' => 'client_id', 'client_secret' => 'client_secret', 'code' => 'code', 'redirect_uri' => 'redirect_uri'],
        ])->andReturns($response = m::mock(ResponseInterface::class));
        $stream = m::mock(StreamInterface::class);
        $stream->allows('__toString')->andReturns('{ "access_token" : "access_token", "refresh_token" : "refresh_token", "expires_in" : 3600 }');
        $response->expects('getBody')->andReturns($stream);
        $user = $provider->user();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('foo', $user->id);
        $this->assertSame('access_token', $user->token);
        $this->assertSame('refresh_token', $user->refreshToken);
        $this->assertSame(3600, $user->expiresIn);
        $this->assertSame($user->id, $provider->user()->id);
    }

    public function testUserReturnsAUserInstanceForTheAuthenticatedFacebookRequest()
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
        $provider = new FacebookTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect_uri'
        );
        $provider->http = m::mock(Client::class);
        $provider->http->expects('post')->with('https://graph.facebook.com/v3.3/oauth/access_token', [
            'form_params' => ['grant_type' => 'authorization_code', 'client_id' => 'client_id', 'client_secret' => 'client_secret', 'code' => 'code', 'redirect_uri' => 'redirect_uri'],
        ])->andReturns($response = m::mock(ResponseInterface::class));
        $stream = m::mock(StreamInterface::class);
        $stream->allows('__toString')->andReturns(json_encode(['access_token' => 'access_token', 'expires' => 5183085]));
        $response->expects('getBody')->andReturns($stream);
        $user = $provider->user();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('foo', $user->id);
        $this->assertSame('access_token', $user->token);
        $this->assertNull($user->refreshToken);
        $this->assertSame(5183085, $user->expiresIn);
        $this->assertSame($user->id, $provider->user()->id);
    }

    public function testExceptionIsThrownIfStateIsInvalid()
    {
        $this->expectException(InvalidStateException::class);

        $request = m::mock(Request::class);
        $request->shouldReceive('session')
            ->andReturn($session = m::mock(SessionContract::class));
        $request->shouldReceive('has')
            ->andReturn(true);
        $request->shouldReceive('input')
            ->with('state')
            ->once()
            ->andReturn(str_repeat('B', 40));

        $session->expects('pull')->with('state')->andReturns(str_repeat('A', 40));
        $provider = new OAuthTwoTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->user();
    }

    public function testExceptionIsThrownIfStateIsNotSet()
    {
        $this->expectException(InvalidStateException::class);

        $request = m::mock(Request::class);
        $request->shouldReceive('session')
            ->andReturn($session = m::mock(SessionContract::class));
        $session->expects('pull')->with('state');
        $provider = new OAuthTwoTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->user();
    }

    public function testUserRefreshesToken()
    {
        $request = m::mock(Request::class);
        $provider = new OAuthTwoTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect_uri'
        );
        $provider->http = m::mock(Client::class);
        $provider->http->expects('post')->with('http://token.url', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['grant_type' => 'refresh_token', 'client_id' => 'client_id', 'client_secret' => 'client_secret', 'refresh_token' => 'refresh_token'],
        ])->andReturns($response = m::mock(ResponseInterface::class));
        $stream = m::mock(StreamInterface::class);
        $stream->allows('__toString')->andReturns('{ "access_token" : "access_token", "refresh_token" : "refresh_token", "expires_in" : 3600, "scope" : "scope1,scope2" }');
        $response->expects('getBody')->andReturns($stream);
        $token = $provider->refreshToken('refresh_token');

        $this->assertInstanceOf(Token::class, $token);
        $this->assertSame('access_token', $token->token);
        $this->assertSame('refresh_token', $token->refreshToken);
        $this->assertSame(3600, $token->expiresIn);
        $this->assertSame(['scope1', 'scope2'], $token->approvedScopes);
    }

    public function testUserRefreshesGoogleToken()
    {
        $request = m::mock(Request::class);
        $provider = new GoogleTestProviderStub(
            $request,
            'client_id',
            'client_secret',
            'redirect_uri'
        );
        $provider->http = m::mock(Client::class);
        $provider->http->expects('post')->with('http://token.url', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['grant_type' => 'refresh_token', 'client_id' => 'client_id', 'client_secret' => 'client_secret', 'refresh_token' => 'refresh_token'],
        ])->andReturns($response = m::mock(ResponseInterface::class));
        $stream = m::mock(StreamInterface::class);
        $stream->allows('__toString')->andReturns('{ "access_token" : "access_token", "expires_in" : 3600, "scope" : "scope1 scope2" }');
        $response->expects('getBody')->andReturns($stream);
        $token = $provider->refreshToken('refresh_token');

        $this->assertInstanceOf(Token::class, $token);
        $this->assertSame('access_token', $token->token);
        $this->assertSame('refresh_token', $token->refreshToken);
        $this->assertSame(3600, $token->expiresIn);
        $this->assertSame(['scope1', 'scope2'], $token->approvedScopes);
    }
}
