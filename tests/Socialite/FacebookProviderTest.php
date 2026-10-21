<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\FacebookProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;

class FacebookProviderTest extends TestCase
{
    public function testMapUserToObjectWithAccessTokenResponse(): void
    {
        $provider = $this->getProvider();

        $method = new ReflectionMethod($provider, 'mapUserToObject');

        $user = $method->invoke($provider, [
            'id' => '123456',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'link' => 'https://facebook.com/testuser',
            'picture' => [
                'data' => [
                    'url' => 'https://platform-lookaside.fbsbx.com/photo.jpg',
                ],
            ],
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('https://platform-lookaside.fbsbx.com/photo.jpg', $user->getAvatar());
        $this->assertSame('https://platform-lookaside.fbsbx.com/photo.jpg', $user->avatar_original);
    }

    public function testMapUserToObjectWithOidcTokenResponse(): void
    {
        $provider = $this->getProvider();

        $method = new ReflectionMethod($provider, 'mapUserToObject');

        $user = $method->invoke($provider, [
            'sub' => '123456',
            'id' => '123456',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'picture' => 'https://platform-lookaside.fbsbx.com/oidc-photo.jpg',
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('https://platform-lookaside.fbsbx.com/oidc-photo.jpg', $user->getAvatar());
        $this->assertSame('https://platform-lookaside.fbsbx.com/oidc-photo.jpg', $user->avatar_original);
    }

    public function testAccessTokenProfileRequestPreservesDocumentedQueryParameters(): void
    {
        $provider = $this->getProvider();
        $httpClient = m::mock(Client::class);
        $provider->setHttpClient($httpClient);

        $httpClient->expects('get')->with('https://graph.facebook.com/v23.0/me', [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
            ],
            RequestOptions::QUERY => [
                'access_token' => 'access-token',
                'fields' => 'name,email,gender,verified,link,picture.width(1920)',
                'appsecret_proof' => hash_hmac('sha256', 'access-token', 'client_secret'),
            ],
        ])->andReturn(new Response(body: json_encode([
            'id' => '123456',
            'name' => 'Test User',
        ])));

        $this->assertSame('123456', $provider->userFromToken('access-token')->getId());
    }

    private function getProvider(): FacebookProvider
    {
        return new FacebookProvider(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect',
        );
    }
}
