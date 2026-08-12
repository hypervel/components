<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\GitlabProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;

class GitlabProviderTest extends TestCase
{
    public function testUserRequestUsesTheVersionedApiAndBearerAuthorization(): void
    {
        $provider = new GitlabProvider(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect',
        );
        $httpClient = m::mock(Client::class);
        $provider->setHttpClient($httpClient);

        $httpClient->expects('get')->with('https://gitlab.com/api/v4/user', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer access-token'],
        ])->andReturn(new Response(body: json_encode([
            'id' => 1,
            'username' => 'taylor',
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'avatar_url' => 'https://example.com/avatar.jpg',
        ])));

        $user = $provider->userFromToken('access-token');

        $this->assertSame(1, $user->getId());
        $this->assertSame('taylor@example.com', $user->getEmail());
    }
}
