<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\BitbucketProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;

class BitbucketProviderTest extends TestCase
{
    public function testUserAndEmailRequestsUseBearerAuthorizationWithoutTokenQueries(): void
    {
        $provider = new BitbucketProvider(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect',
        );
        $httpClient = m::mock(Client::class);
        $provider->setHttpClient($httpClient);

        $httpClient->expects('get')->with('https://api.bitbucket.org/2.0/user', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer access-token'],
        ])->andReturn(new Response(body: json_encode([
            'uuid' => 'user-id',
            'username' => 'taylor',
            'display_name' => 'Taylor Otwell',
            'links' => ['avatar' => ['href' => 'https://example.com/avatar.jpg']],
        ])));
        $httpClient->expects('get')->with('https://api.bitbucket.org/2.0/user/emails', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer access-token'],
        ])->andReturn(new Response(body: json_encode([
            'values' => [[
                'type' => 'email',
                'is_primary' => true,
                'is_confirmed' => true,
                'email' => 'taylor@example.com',
            ]],
        ])));

        $user = $provider->userFromToken('access-token');

        $this->assertSame('user-id', $user->getId());
        $this->assertSame('taylor@example.com', $user->getEmail());
    }
}
