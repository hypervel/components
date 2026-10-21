<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use ErrorException;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\LinkedInProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use ReflectionMethod;

class LinkedInProviderTest extends TestCase
{
    public function testItCanMapAUserWithoutAnEmailAddress(): void
    {
        $request = m::mock(Request::class);
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
            'client_id',
            'client_secret',
            'redirect'
        );
        $provider->stateless();
        $provider->setHttpClient($guzzle);

        $user = $provider->user();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame($userId, $user->getId());
        $this->assertNull($user->getEmail());
    }

    public function testItCanMapAUserWhenTheStillImageKeyIsMissing(): void
    {
        $request = m::mock(Request::class);
        $request->allows('input')->with('code')->andReturns('fake-code');

        $stream = m::mock(StreamInterface::class);
        $stream->allows('__toString')->andReturns(json_encode(['access_token' => 'fake-token']));

        $accessTokenResponse = m::mock(ResponseInterface::class);
        $accessTokenResponse->allows('getBody')->andReturns($stream);

        $basicProfileStream = m::mock(StreamInterface::class);
        $basicProfileStream->allows('__toString')->andReturns(json_encode([
            'id' => $userId = 1,
            'profilePicture' => [
                'displayImage~' => [
                    'elements' => [
                        [
                            'identifiers' => [
                                ['identifier' => 'https://media.licdn.com/avatar.jpg'],
                            ],
                            'data' => [],
                        ],
                    ],
                ],
            ],
        ]));

        $basicProfileResponse = m::mock(ResponseInterface::class);
        $basicProfileResponse->allows('getBody')->andReturns($basicProfileStream);

        $emailAddressStream = m::mock(StreamInterface::class);
        $emailAddressStream->allows('__toString')->andReturns(json_encode(['elements' => []]));

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

        $provider = new LinkedInProvider($request, 'client_id', 'client_secret', 'redirect');
        $provider->stateless();
        $provider->setHttpClient($guzzle);

        // LinkedIn omits the StillImage key for some accounts, and Hypervel turns
        // the resulting "Undefined array key" warning into an exception.
        set_error_handler(function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $user = $provider->user();
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame($userId, $user->getId());
        $this->assertNull($user->getAvatar());
    }

    public function testMapUserSkipsImagesWithoutStillImageMetadata(): void
    {
        $provider = new LinkedInProvider(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect',
        );
        $method = new ReflectionMethod($provider, 'mapUserToObject');

        $image = fn (int $width, string $url): array => [
            'data' => [
                'com.linkedin.digitalmedia.mediaartifact.StillImage' => [
                    'storageSize' => ['width' => $width],
                ],
            ],
            'identifiers' => [['identifier' => $url]],
        ];

        $user = $method->invoke($provider, [
            'id' => 1,
            'firstName' => [
                'preferredLocale' => ['language' => 'en', 'country' => 'US'],
                'localized' => ['en_US' => 'Taylor'],
            ],
            'lastName' => [
                'preferredLocale' => ['language' => 'en', 'country' => 'US'],
                'localized' => ['en_US' => 'Otwell'],
            ],
            'profilePicture' => [
                'displayImage~' => [
                    'elements' => [
                        ['data' => [], 'identifiers' => []],
                        $image(100, 'https://example.com/avatar.jpg'),
                        ['data' => [], 'identifiers' => []],
                        $image(800, 'https://example.com/avatar-original.jpg'),
                        $image(1200, 'https://example.com/unrelated-image.jpg'),
                    ],
                ],
            ],
        ]);

        $this->assertSame('https://example.com/avatar.jpg', $user->getAvatar());
        $this->assertSame('https://example.com/avatar-original.jpg', $user->avatar_original);
    }
}
