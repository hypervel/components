<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Request;
use Hypervel\Socialite\Contracts\User as UserContract;
use Hypervel\Socialite\Two\LinkedInOpenIdProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class LinkedInOpenIdProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testResponse(): void
    {
        $user = $this->fromResponse([
            'sub' => 'asdfgh',
            'given_name' => 'Nuno',
            'picture' => 'https://media.licdn.com/dms/image/D4D03AQmZFgJNqeNNk',
            'name' => 'Nuno Maduro',
            'family_name' => 'Maduro',
            'email' => 'nuno@laravel.com',
            'email_verified' => true,
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('asdfgh', $user->getId());
        $this->assertNull($user->getNickname());
        $this->assertSame('Nuno Maduro', $user->getName());
        $this->assertSame('nuno@laravel.com', $user->getEmail());
        $this->assertSame('https://media.licdn.com/dms/image/D4D03AQmZFgJNqeNNk', $user->getAvatar());

        $this->assertSame([
            'id' => 'asdfgh',
            'nickname' => null,
            'name' => 'Nuno Maduro',
            'first_name' => 'Nuno',
            'last_name' => 'Maduro',
            'email' => 'nuno@laravel.com',
            'email_verified' => true,
            'avatar' => 'https://media.licdn.com/dms/image/D4D03AQmZFgJNqeNNk',
            'avatar_original' => 'https://media.licdn.com/dms/image/D4D03AQmZFgJNqeNNk',
        ], $user->attributes);
    }

    public function testMissingEmailAndAvatar(): void
    {
        $user = $this->fromResponse([
            'sub' => 'asdfgh',
            'given_name' => 'Nuno',
            'name' => 'Nuno Maduro',
            'family_name' => 'Maduro',
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('asdfgh', $user->getId());
        $this->assertNull($user->getNickname());
        $this->assertSame('Nuno Maduro', $user->getName());
        $this->assertNull($user->getEmail());
        $this->assertNull($user->getAvatar());

        $this->assertSame([
            'id' => 'asdfgh',
            'nickname' => null,
            'name' => 'Nuno Maduro',
            'first_name' => 'Nuno',
            'last_name' => 'Maduro',
            'email' => null,
            'email_verified' => null,
            'avatar' => null,
            'avatar_original' => null,
        ], $user->attributes);
    }

    /**
     * Resolve a user from the given profile response.
     */
    protected function fromResponse(array $response): UserContract
    {
        $request = Request::create('/callback', parameters: ['code' => 'fake-code']);
        $guzzle = Double::for(Client::class)->strict();
        $guzzle->expects('post')->returns(new Response(body: json_encode(['access_token' => 'fake-token'])));
        $guzzle->expects('get')->with('https://api.linkedin.com/v2/userinfo', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer fake-token',
                'X-RestLi-Protocol-Version' => '2.0.0',
            ],
            RequestOptions::QUERY => [
                'projection' => '(sub,email,email_verified,name,given_name,family_name,picture)',
            ],
        ])->returns(new Response(body: json_encode($response)));

        $provider = new LinkedInOpenIdProvider(
            $request,
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->stateless();
        $provider->setHttpClient($guzzle);

        return $provider->user();
    }
}
