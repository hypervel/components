<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hypervel\Context\Context;
use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Http\Contracts\ResponseContract;
use Hypervel\Session\Contracts\Session as SessionContract;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\Socialite\Fixtures\OpenIdTestProviderStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 * @coversNothing
 */
class OpenIdProviderTest extends TestCase
{
    public function tearDown(): void
    {
        parent::tearDown();

        Context::destroyAll();
    }

    public function testRedirectGeneratesTheProperRedirectResponseWithoutPKCE()
    {
        $request = m::mock(RequestContract::class);
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

        $response = m::mock(ResponseContract::class);
        $response->shouldReceive('redirect')
            ->once()
            ->with(m::on(function ($url) use (&$state) {
                return $url === "http://auth.url?client_id=client_id&redirect_uri=redirect&scope=&response_type=code&state={$state}";
            }))->andReturn($redirectResponse = m::mock(ResponseInterface::class));

        $session->expects('put')->twice()->withArgs($closure);
        $provider = new OpenIdTestProviderStub(
            $request,
            $response,
            'client_id',
            'client_secret',
            'redirect'
        );

        $this->assertSame(
            $redirectResponse,
            $provider->redirect()
        );
    }

    public function testUserReturnsAUserInstanceForTheAuthenticatedRequest()
    {
        $request = m::mock(RequestContract::class);
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
            m::mock(ResponseContract::class),
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
}
