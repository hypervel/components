<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Http\Contracts\ResponseContract;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\Socialite\Fixtures\GoogleTestProviderStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 * @coversNothing
 */
class GoogleProviderTest extends TestCase
{
    public function testMapUserFromAccessToken()
    {
        $provider = new GoogleTestProviderStub(
            m::mock(RequestContract::class),
            m::mock(ResponseContract::class),
            'client_id',
            'client_secret',
            'redirect_uri'
        );

        $provider->http = m::mock(Client::class);

        $provider->http->allows('get')->with('https://www.googleapis.com/oauth2/v3/userinfo', [
            RequestOptions::QUERY => [
                'prettyPrint' => 'false',
            ],
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer fake-token',
            ],
        ])->andReturns($response = m::mock(ResponseInterface::class));

        $response->allows('getBody')->andReturns(m::mock(StreamInterface::class));

        $user = $provider->userFromToken('fake-token');

        $this->assertInstanceOf(User::class, $user);
    }
}
