<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\BitbucketProvider;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class BitbucketProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testUserAndEmailRequestsUseBearerAuthorizationWithoutTokenQueries(): void
    {
        $provider = new BitbucketProvider(
            new Request,
            'client_id',
            'client_secret',
            'redirect',
        );
        $httpClient = Double::for(Client::class)->strict();
        $provider->setHttpClient($httpClient);

        $httpClient->expects('get')->with('https://api.bitbucket.org/2.0/user', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer access-token'],
        ])->returns(new Response(body: json_encode([
            'uuid' => 'user-id',
            'username' => 'taylor',
            'display_name' => 'Taylor Otwell',
            'links' => ['avatar' => ['href' => 'https://example.com/avatar.jpg']],
        ])));
        $httpClient->expects('get')->with('https://api.bitbucket.org/2.0/user/emails', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer access-token'],
        ])->returns(new Response(body: json_encode([
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
