<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Hypervel\Context\Context;
use Hypervel\Socialite\Exceptions\DriverMissingConfigurationException;
use Hypervel\Socialite\SocialiteManager;
use Hypervel\Socialite\Two\GithubProvider;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SocialiteManagerTest extends TestCase
{
    public function tearDown(): void
    {
        parent::tearDown();

        Context::destroyAll();
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')
            ->set('services.github', [
                'client_id' => 'github-client-id',
                'client_secret' => 'github-client-secret',
                'redirect' => 'http://your-callback-url',
            ]);
    }

    public function testItCanInstantiateTheGithubDriver()
    {
        $factory = $this->app->make(SocialiteManager::class);

        $provider = $factory->driver('github');

        $this->assertInstanceOf(GithubProvider::class, $provider);
    }

    public function testItCanInstantiateTheGithubDriverWithScopesFromConfigArray()
    {
        $factory = $this->app->make(SocialiteManager::class);
        $this->app->make('config')
            ->set('services.github', [
                'client_id' => 'github-client-id',
                'client_secret' => 'github-client-secret',
                'redirect' => 'http://your-callback-url',
                'scopes' => ['user:email', 'read:user'],
            ]);
        $provider = $factory->driver('github');
        $this->assertSame(['user:email', 'read:user'], $provider->getScopes());
    }

    public function testItCanInstantiateTheGithubDriverWithScopesWithoutArrayFromConfig()
    {
        $factory = $this->app->make(SocialiteManager::class);
        $provider = $factory->driver('github');
        $this->assertSame(['user:email'], $provider->getScopes());
    }

    public function testItCanInstantiateTheGithubDriverWithScopesFromConfigArrayMergedByProgrammaticScopesUsingScopesMethod()
    {
        $factory = $this->app->make(SocialiteManager::class);
        $this->app->make('config')
            ->set('services.github', [
                'client_id' => 'github-client-id',
                'client_secret' => 'github-client-secret',
                'redirect' => 'http://your-callback-url',
                'scopes' => ['user:email'],
            ]);
        $provider = $factory->driver('github')->scopes(['read:user']);
        $this->assertSame(['user:email', 'read:user'], $provider->getScopes());
    }

    public function testItCanInstantiateTheGithubDriverWithScopesFromConfigArrayOverwrittenByProgrammaticScopesUsingSetScopesMethod()
    {
        $factory = $this->app->make(SocialiteManager::class);
        $this->app->make('config')
            ->set('services.github', [
                'client_id' => 'github-client-id',
                'client_secret' => 'github-client-secret',
                'redirect' => 'http://your-callback-url',
                'scopes' => ['user:email'],
            ]);
        $provider = $factory->driver('github')->setScopes(['read:user']);
        $this->assertSame(['read:user'], $provider->getScopes());
    }

    public function testItThrowsExceptionWhenClientSecretIsMissing()
    {
        $this->expectException(DriverMissingConfigurationException::class);
        $this->expectExceptionMessage('Missing required configuration keys [client_secret] for [Hypervel\Socialite\Two\GithubProvider] OAuth provider.');

        $factory = $this->app->make(SocialiteManager::class);

        $this->app->make('config')
            ->set('services.github', [
                'client_id' => 'github-client-id',
                'redirect' => 'http://your-callback-url',
            ]);

        $factory->driver('github');
    }

    public function testItThrowsExceptionWhenClientIdIsMissing()
    {
        $this->expectException(DriverMissingConfigurationException::class);
        $this->expectExceptionMessage('Missing required configuration keys [client_id] for [Hypervel\Socialite\Two\GithubProvider] OAuth provider.');

        $factory = $this->app->make(SocialiteManager::class);

        $this->app->make('config')
            ->set('services.github', [
                'client_secret' => 'github-client-secret',
                'redirect' => 'http://your-callback-url',
            ]);

        $factory->driver('github');
    }

    public function testItThrowsExceptionWhenRedirectIsMissing()
    {
        $this->expectException(DriverMissingConfigurationException::class);
        $this->expectExceptionMessage('Missing required configuration keys [redirect] for [Hypervel\Socialite\Two\GithubProvider] OAuth provider.');

        $factory = $this->app->make(SocialiteManager::class);

        $this->app->make('config')
            ->set('services.github', [
                'client_id' => 'github-client-id',
                'client_secret' => 'github-client-secret',
            ]);

        $factory->driver('github');
    }

    public function testItThrowsExceptionWhenConfigurationIsCompletelyMissing()
    {
        $this->expectException(DriverMissingConfigurationException::class);
        $this->expectExceptionMessage('Missing required configuration keys [client_id, client_secret, redirect] for [Hypervel\Socialite\Two\GithubProvider] OAuth provider.');

        $factory = $this->app->make(SocialiteManager::class);

        $this->app->make('config')
            ->set('services.github', null);

        $factory->driver('github');
    }
}
