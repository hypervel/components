<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Hypervel\Context\Context;
use Hypervel\Contracts\Http\Request as RequestContract;
use Hypervel\Contracts\Http\Response as ResponseContract;
use Hypervel\Socialite\Two\LinkedInProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 * @coversNothing
 */
class LinkedInProviderTest extends TestCase
{
    public function tearDown(): void
    {
        parent::tearDown();

        Context::destroyAll();
    }

    public function testMapUserWithoutEmailAndAddress()
    {
        $request = m::mock(RequestContract::class);
        $request->allows('input')->with('code')->andReturns('fake-code');

        $stream = m::mock(StreamInterface::class);
        $stream->allows('__toString')->andReturns(json_encode(['access_token' => 'fake-token']));

        $accessTokenResponse = m::mock(ResponseInterface::class);
        $accessTokenResponse->allows('getBody')->andReturns($stream);

        $basicProfileStream = m::mock(StreamInterface::class);
        $basicProfileStream->allows('__toString')->andReturns(json_encode(['id' => $userId = 1]));

        $basicProfileResponse = m::mock(ResponseInterface::class);
        $basicProfileResponse->allows('getBody')->andReturns($basicProfileStream);

        $emailAddressStream = m::mock(StreamInterface::class);
        $emailAddressStream->allows('__toString')->andReturns(json_encode(['elements' => []]));

        // Make sure email address response contains no values.
        $emailAddressResponse = m::mock(ResponseInterface::class);
        $emailAddressResponse->allows('getBody')->andReturns($emailAddressStream);

        $guzzle = m::mock(Client::class);
        $guzzle->expects('post')->andReturns($accessTokenResponse);
        $guzzle->allows('get')->with('https://api.linkedin.com/v2/me', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer fake-token',
                'X-RestLi-Protocol-Version' => '2.0.0',
            ],
            RequestOptions::QUERY => [
                'projection' => '(id,firstName,lastName,profilePicture(displayImage~:playableStreams),vanityName)',
            ],
        ])->andReturns($basicProfileResponse);
        $guzzle->allows('get')->with('https://api.linkedin.com/v2/emailAddress', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer fake-token',
                'X-RestLi-Protocol-Version' => '2.0.0',
            ],
            RequestOptions::QUERY => [
                'q' => 'members',
                'projection' => '(elements*(handle~))',
            ],
        ])->andReturns($emailAddressResponse);

        $provider = new LinkedInProvider(
            $request,
            m::mock(ResponseContract::class),
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->stateless();
        Context::set(
            '__socialite.providers.' . LinkedInProvider::class . '.httpClient',
            $guzzle
        );

        $user = $provider->user();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame($userId, $user->getId());
        $this->assertNull($user->getEmail());
    }
}
