<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Request;
use Hypervel\Socialite\Contracts\User as UserContract;
use Hypervel\Socialite\Two\SlackOpenIdProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class SlackOpenIdProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testResponse(): void
    {
        $user = $this->fromResponse([
            'sub' => 'U1Q2W3E4R5T',
            'given_name' => 'Maarten',
            'picture' => 'https://secure.gravatar.com/avatar/qwerty-123.jpg?s=512',
            'name' => 'Maarten Paauw',
            'family_name' => 'Paauw',
            'email' => 'maarten.paauw@example.com',
            'https://slack.com/team_id' => 'T0P9O8I7U6Y',
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('U1Q2W3E4R5T', $user->getId());
        $this->assertNull($user->getNickname());
        $this->assertSame('Maarten Paauw', $user->getName());
        $this->assertSame('maarten.paauw@example.com', $user->getEmail());
        $this->assertSame('https://secure.gravatar.com/avatar/qwerty-123.jpg?s=512', $user->getAvatar());

        $this->assertSame([
            'id' => 'U1Q2W3E4R5T',
            'nickname' => null,
            'name' => 'Maarten Paauw',
            'email' => 'maarten.paauw@example.com',
            'avatar' => 'https://secure.gravatar.com/avatar/qwerty-123.jpg?s=512',
            'organization_id' => 'T0P9O8I7U6Y',
        ], $user->attributes);
    }

    public function testMissingEmailAndAvatar(): void
    {
        $user = $this->fromResponse([
            'sub' => 'U1Q2W3E4R5T',
            'given_name' => 'Maarten',
            'name' => 'Maarten Paauw',
            'family_name' => 'Paauw',
            'https://slack.com/team_id' => 'T0P9O8I7U6Y',
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('U1Q2W3E4R5T', $user->getId());
        $this->assertNull($user->getNickname());
        $this->assertSame('Maarten Paauw', $user->getName());
        $this->assertNull($user->getEmail());
        $this->assertNull($user->getAvatar());

        $this->assertSame([
            'id' => 'U1Q2W3E4R5T',
            'nickname' => null,
            'name' => 'Maarten Paauw',
            'email' => null,
            'avatar' => null,
            'organization_id' => 'T0P9O8I7U6Y',
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
        $guzzle->expects('get')->with('https://slack.com/api/openid.connect.userInfo', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer fake-token',
            ],
        ])->returns(new Response(body: json_encode($response)));

        $provider = new SlackOpenIdProvider(
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
