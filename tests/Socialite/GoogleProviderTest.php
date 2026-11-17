<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\GoogleProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class GoogleProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testMapUserFromAccessToken(): void
    {
        // Upstream's stub bypasses both the HTTP request and Google profile mapping.
        $provider = new GoogleProvider(
            new Request,
            'client_id',
            'client_secret',
            'redirect_uri'
        );

        $http = Double::for(Client::class)->strict();
        $provider->setHttpClient($http);

        $http->expects('get')->with('https://www.googleapis.com/oauth2/v3/userinfo', [
            RequestOptions::QUERY => [
                'prettyPrint' => 'false',
            ],
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer fake-token',
            ],
        ])->returns(new Response(body: json_encode(['sub' => 'user-id', 'email' => 'taylor@example.com'])));

        $user = $provider->userFromToken('fake-token');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('user-id', $user->getId());
        $this->assertSame('taylor@example.com', $user->getEmail());
    }
}
