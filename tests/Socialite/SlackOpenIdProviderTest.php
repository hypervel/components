<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Hypervel\Context\Context;
use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Http\Contracts\ResponseContract;
use Hypervel\Socialite\Contracts\User as UserContract;
use Hypervel\Socialite\Two\SlackOpenIdProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 * @coversNothing
 */
class SlackOpenIdProviderTest extends TestCase
{
    public function tearDown(): void
    {
        parent::tearDown();

        Context::destroy('socialite.client.providers.' . SlackOpenIdProvider::class);
    }

    public function testResponse()
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

    public function testMissingEmailAndAvatar()
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

    protected function fromResponse(array $response): UserContract
    {
        $request = m::mock(RequestContract::class);
        $request->allows('input')->with('code')->andReturns('fake-code');

        $stream = m::mock(StreamInterface::class);
        $stream->allows('__toString')->andReturns(json_encode(['access_token' => 'fake-token']));

        $accessTokenResponse = m::mock(ResponseInterface::class);
        $accessTokenResponse->allows('getBody')->andReturns($stream);

        $basicProfileStream = m::mock(StreamInterface::class);
        $basicProfileStream->allows('__toString')->andReturns(json_encode($response));

        $basicProfileResponse = m::mock(ResponseInterface::class);
        $basicProfileResponse->allows('getBody')->andReturns($basicProfileStream);

        $guzzle = m::mock(Client::class);
        $guzzle->expects('post')->andReturns($accessTokenResponse);
        $guzzle->allows('get')->with('https://slack.com/api/openid.connect.userInfo', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer fake-token',
            ],
        ])->andReturns($basicProfileResponse);

        $provider = new SlackOpenIdProvider(
            $request,
            m::mock(ResponseContract::class),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->stateless();
        Context::set(
            'socialite.client.providers.' . SlackOpenIdProvider::class,
            $guzzle
        );

        return $provider->user();
    }
}
