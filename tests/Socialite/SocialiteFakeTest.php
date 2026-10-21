<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Hypervel\Socialite\Contracts\Factory;
use Hypervel\Socialite\Socialite;
use Hypervel\Socialite\SocialiteManager;
use Hypervel\Socialite\SocialiteServiceProvider;
use Hypervel\Socialite\Testing\FakeProvider;
use Hypervel\Socialite\Testing\SocialiteFake;
use Hypervel\Socialite\Two\GoogleProvider;
use Hypervel\Socialite\Two\User as OAuth2User;
use Hypervel\Testbench\TestCase;

enum SocialiteFakeTestIntIdentifier: int
{
    case Zero = 0;
}

class SocialiteFakeTestUser extends OAuth2User
{
}

class SocialiteFakeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SocialiteServiceProvider::class];
    }

    public function testItCanFakeADriverWithAUser(): void
    {
        $user = (new OAuth2User)->map([
            'id' => '123',
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Socialite::fake('github', $user);

        $this->assertInstanceOf(SocialiteFake::class, $this->app->make(Factory::class));
        $this->assertInstanceOf(FakeProvider::class, Socialite::driver('github'));

        $retrievedUser = Socialite::driver('github')->user();

        $this->assertSame('123', $retrievedUser->getId());
        $this->assertSame('Test User', $retrievedUser->getName());
        $this->assertSame('test@example.com', $retrievedUser->getEmail());
    }

    public function testItCanFakeADriverWithAFakeUser(): void
    {
        Socialite::fake('github', OAuth2User::fake([
            'id' => '123',
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]));

        $user = Socialite::driver('github')->user();

        $this->assertSame('123', $user->getId());
        $this->assertSame('Test User', $user->getName());
        $this->assertSame('test@example.com', $user->getEmail());
        $this->assertSame('fake-token', $user->token);
    }

    public function testItCanCreateAFakeOauth2User(): void
    {
        $user = OAuth2User::fake();

        $this->assertInstanceOf(OAuth2User::class, $user);
        $this->assertSame('123456789', $user->getId());
        $this->assertSame('testuser', $user->getNickname());
        $this->assertSame('Test User', $user->getName());
        $this->assertSame('test@example.com', $user->getEmail());
        $this->assertSame('https://example.com/avatar.jpg', $user->getAvatar());
        $this->assertSame('fake-token', $user->token);
        $this->assertSame('fake-refresh-token', $user->refreshToken);
        $this->assertSame(3600, $user->expiresIn);
        $this->assertSame([], $user->approvedScopes);
        $this->assertSame([], $user->accessTokenResponseBody);
        $this->assertSame('Test User', $user['name']);
    }

    public function testItCanCreateAFakeOauth2UserWithAttributes(): void
    {
        $user = OAuth2User::fake([
            'id' => '987654321',
            'nickname' => 'jane',
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'avatar' => 'https://example.com/avatar.jpg',
            'token' => 'custom-token',
            'refreshToken' => 'custom-refresh-token',
            'expiresIn' => 7200,
            'approvedScopes' => ['read:user'],
            'accessTokenResponseBody' => ['token_type' => 'Bearer'],
            'organization' => 'Hypervel',
        ]);

        $this->assertSame('987654321', $user->getId());
        $this->assertSame('jane', $user->getNickname());
        $this->assertSame('Jane Doe', $user->getName());
        $this->assertSame('jane@example.com', $user->getEmail());
        $this->assertSame('https://example.com/avatar.jpg', $user->getAvatar());
        $this->assertSame('custom-token', $user->token);
        $this->assertSame('custom-refresh-token', $user->refreshToken);
        $this->assertSame(7200, $user->expiresIn);
        $this->assertSame(['read:user'], $user->approvedScopes);
        $this->assertSame(['token_type' => 'Bearer'], $user->accessTokenResponseBody);
        $this->assertSame('Hypervel', $user->organization);
        $this->assertSame('Hypervel', $user['organization']);
    }

    // REMOVED: Laravel Socialite's OAuth 1 user fake tests do not apply; OAuth 1 is unsupported.

    public function testItCanFakeADriverWithAClosure(): void
    {
        Socialite::fake('github', function () {
            return (new OAuth2User)->map([
                'id' => '456',
                'name' => 'Closure User',
                'email' => 'closure@example.com',
            ]);
        });

        $user = Socialite::driver('github')->user();

        $this->assertSame('456', $user->getId());
        $this->assertSame('Closure User', $user->getName());
    }

    public function testItCanFakeMultipleDrivers(): void
    {
        Socialite::fake('github', (new OAuth2User)->map(['id' => 'github-123']));
        Socialite::fake('google', (new OAuth2User)->map(['id' => 'google-456']));

        $this->assertSame('github-123', Socialite::driver('github')->user()->getId());
        $this->assertSame('google-456', Socialite::driver('google')->user()->getId());
    }

    public function testItCanResolveAFakedDriverUsingAnIntegerEnum(): void
    {
        Socialite::fake('0', (new OAuth2User)->map(['id' => 'enum-123']));

        $provider = Socialite::driver(SocialiteFakeTestIntIdentifier::Zero);

        $this->assertInstanceOf(FakeProvider::class, $provider);
        $this->assertSame('enum-123', $provider->user()->getId());
    }

    public function testOAuthTwoUserFakeUsesLateStaticBinding(): void
    {
        $this->assertInstanceOf(SocialiteFakeTestUser::class, SocialiteFakeTestUser::fake());
    }

    public function testItReturnsFakeRedirectResponse(): void
    {
        Socialite::fake('github', (new OAuth2User)->map(['id' => '123']));

        $response = Socialite::driver('github')->redirect();

        $this->assertSame('https://socialite.fake/github/authorize', $response->getTargetUrl());
    }

    public function testItForwardsCallsToTheRealProviderMethods(): void
    {
        $this->app->make('config')->set('services.github', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost/callback',
        ]);

        Socialite::fake('github', (new OAuth2User)->map(['id' => '123']));

        $provider = Socialite::driver('github');

        // Verify that methods are forwarded to the real provider
        $provider->stateless();
        $provider->scopes(['user', 'repo']);
        $provider->setScopes(['user:email']);
        $provider->redirectUrl('http://example.com/callback');
        $provider->with(['custom' => 'param']);
        $provider->enablePKCE();

        // Verify that the fake user is returned despite calling other methods
        $user = $provider->user();

        $this->assertSame('123', $user->getId());
    }

    public function testItPreservesDecoratorPatternWhenChainingMethods(): void
    {
        $this->app->make('config')->set('services.github', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost/callback',
        ]);

        Socialite::fake('github', (new OAuth2User)->map(['id' => '123']));

        $provider = Socialite::driver('github');
        $this->assertInstanceOf(FakeProvider::class, $provider);

        $chainedProvider = $provider->stateless()
            ->scopes(['user', 'repo'])
            ->setScopes(['user:email'])
            ->redirectUrl('http://example.com/callback')
            ->with(['custom' => 'param'])
            ->enablePKCE();

        $this->assertInstanceOf(FakeProvider::class, $chainedProvider, 'FakeProvider should be returned, not the real provider');
        $this->assertSame($provider, $chainedProvider);

        $user = $chainedProvider->user();
        $this->assertSame('123', $user->getId());
    }

    public function testItReturnsRealDriverWhenNotFaked(): void
    {
        $this->app->make('config')->set('services.github', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost/callback',
        ]);

        $this->app->make('config')->set('services.google', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost/callback',
        ]);

        // Fake only github
        Socialite::fake('github', (new OAuth2User)->map(['id' => '123']));

        // Github should return the fake provider
        $this->assertInstanceOf(FakeProvider::class, Socialite::driver('github'));

        // Google should return the real provider since it wasn't faked
        $this->assertInstanceOf(GoogleProvider::class, Socialite::driver('google'));
    }

    public function testFactoryFakeDoesNotReplaceTheConcreteManager(): void
    {
        $manager = $this->app->make(SocialiteManager::class);

        Socialite::fake('github', OAuth2User::fake());

        $this->assertInstanceOf(SocialiteFake::class, $this->app->make(Factory::class));
        $this->assertSame($manager, $this->app->make(SocialiteManager::class));
    }
}
