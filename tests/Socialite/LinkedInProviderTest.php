<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use ErrorException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\LinkedInProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use ReflectionMethod;

class LinkedInProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testItCanMapAUserWithoutAnEmailAddress(): void
    {
        $request = Request::create('/callback', parameters: ['code' => 'fake-code']);

        $accessTokenResponse = new Response(body: json_encode(['access_token' => 'fake-token']));

        $basicProfileResponse = new Response(body: json_encode(['id' => $userId = 1]));

        $emailAddressResponse = new Response(body: json_encode(['elements' => []]));

        $guzzle = Double::for(Client::class)->strict();
        $guzzle->expects('post')->returns($accessTokenResponse);
        $guzzle->expects('get')->with('https://api.linkedin.com/v2/me', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer fake-token',
                'X-RestLi-Protocol-Version' => '2.0.0',
            ],
            RequestOptions::QUERY => [
                'projection' => '(id,firstName,lastName,profilePicture(displayImage~:playableStreams),vanityName)',
            ],
        ])->returns($basicProfileResponse);
        $guzzle->expects('get')->with('https://api.linkedin.com/v2/emailAddress', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer fake-token',
                'X-RestLi-Protocol-Version' => '2.0.0',
            ],
            RequestOptions::QUERY => [
                'q' => 'members',
                'projection' => '(elements*(handle~))',
            ],
        ])->returns($emailAddressResponse);

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
        $request = Request::create('/callback', parameters: ['code' => 'fake-code']);

        $accessTokenResponse = new Response(body: json_encode(['access_token' => 'fake-token']));

        $basicProfileResponse = new Response(body: json_encode([
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

        $emailAddressResponse = new Response(body: json_encode(['elements' => []]));

        $guzzle = Double::for(Client::class)->strict();
        $guzzle->expects('post')->returns($accessTokenResponse);
        $guzzle->expects('get')->with('https://api.linkedin.com/v2/me', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer fake-token',
                'X-RestLi-Protocol-Version' => '2.0.0',
            ],
            RequestOptions::QUERY => [
                'projection' => '(id,firstName,lastName,profilePicture(displayImage~:playableStreams),vanityName)',
            ],
        ])->returns($basicProfileResponse);
        $guzzle->expects('get')->with('https://api.linkedin.com/v2/emailAddress', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer fake-token',
                'X-RestLi-Protocol-Version' => '2.0.0',
            ],
            RequestOptions::QUERY => [
                'q' => 'members',
                'projection' => '(elements*(handle~))',
            ],
        ])->returns($emailAddressResponse);

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
            new Request,
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
