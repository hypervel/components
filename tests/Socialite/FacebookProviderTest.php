<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Hypervel\Http\Request;
use Hypervel\Socialite\Two\FacebookProvider;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;

class FacebookProviderTest extends TestCase
{
    public function testMapUserToObjectWithAccessTokenResponse()
    {
        $provider = new FacebookProvider(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect'
        );

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

    public function testMapUserToObjectWithOidcTokenResponse()
    {
        $provider = new FacebookProvider(
            m::mock(Request::class),
            'client_id',
            'client_secret',
            'redirect'
        );

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
}
