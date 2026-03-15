<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Socialite\One\MissingTemporaryCredentialsException;
use Hypervel\Socialite\One\MissingVerifierException;
use Hypervel\Socialite\One\User as SocialiteUser;
use Hypervel\Tests\Socialite\Fixtures\OAuthOneTestProviderStub;
use Hypervel\Tests\TestCase;
use League\OAuth1\Client\Credentials\TemporaryCredentials;
use League\OAuth1\Client\Credentials\TokenCredentials;
use League\OAuth1\Client\Server\Twitter;
use League\OAuth1\Client\Server\User;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class OAuthOneTest extends TestCase
{
    public function testRedirectGeneratesTheProperRedirectResponse()
    {
        $server = m::mock(Twitter::class);
        $temp = m::mock(TemporaryCredentials::class);
        $server->expects('getTemporaryCredentials')->andReturns($temp);
        $server->expects('getAuthorizationUrl')->with($temp)->andReturns('http://auth.url');
        $request = m::mock(Request::class);
        $request->shouldReceive('session')
            ->once()
            ->andReturn($session = m::mock(SessionContract::class));
        $session->expects('put')->with('oauth.temp', $temp);

        $provider = new OAuthOneTestProviderStub($request, $server);
        $response = $provider->redirect();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('http://auth.url', $response->getTargetUrl());
    }

    public function testUserReturnsAUserInstanceForTheAuthenticatedRequest()
    {
        $server = m::mock(Twitter::class);
        $temp = m::mock(TemporaryCredentials::class);
        $server->expects('getTokenCredentials')->with($temp, 'oauth_token', 'oauth_verifier')->andReturns(
            $token = m::mock(TokenCredentials::class)
        );
        $server->expects('getUserDetails')->with($token, false)->andReturns($user = m::mock(User::class));
        $token->expects('getIdentifier')->twice()->andReturns('identifier');
        $token->expects('getSecret')->twice()->andReturns('secret');
        $user->uid = 'uid';
        $user->email = 'foo@bar.com';
        $user->extra = ['extra' => 'extra'];

        $request = m::mock(Request::class);
        $request->shouldReceive('has')
            ->andReturn(true);
        $request->shouldReceive('input')
            ->with('oauth_token')
            ->once()
            ->andReturn('oauth_token');
        $request->shouldReceive('input')
            ->with('oauth_verifier')
            ->once()
            ->andReturn('oauth_verifier');
        $request->shouldReceive('session')
            ->once()
            ->andReturn($session = m::mock(SessionContract::class));
        $session->expects('get')->with('oauth.temp')->andReturns($temp);

        $provider = new OAuthOneTestProviderStub($request, $server);
        $user = $provider->user();

        $this->assertInstanceOf(SocialiteUser::class, $user);
        $this->assertSame('uid', $user->id);
        $this->assertSame('foo@bar.com', $user->email);
        $this->assertSame(['extra' => 'extra'], $user->user);
    }

    public function testExceptionIsThrownWhenVerifierIsMissing()
    {
        $this->expectException(MissingVerifierException::class);

        $server = m::mock(Twitter::class);
        $request = m::mock(Request::class);
        $request->shouldReceive('has')
            ->andReturn(false);

        $provider = new OAuthOneTestProviderStub($request, $server);
        $provider->user();
    }

    public function testExceptionIsThrownWhenTemporaryCredentialsAreMissing()
    {
        $this->expectException(MissingTemporaryCredentialsException::class);

        $server = m::mock(Twitter::class);
        $request = m::mock(Request::class);
        $request->shouldReceive('has')
            ->andReturn(true);
        $request->shouldReceive('session')
            ->once()
            ->andReturn($session = m::mock(SessionContract::class));
        $session->expects('get')->with('oauth.temp')->andReturns(null);

        $provider = new OAuthOneTestProviderStub($request, $server);
        $provider->user();
    }
}
