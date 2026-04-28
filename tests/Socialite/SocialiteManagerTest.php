<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Hypervel\Config\Repository;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Socialite\Exceptions\DriverMissingConfigurationException;
use Hypervel\Socialite\SocialiteManager;
use Hypervel\Socialite\Two\GithubProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Socialite\Fixtures\GenericTestProviderStub;
use ReflectionProperty;
use Swoole\Coroutine\Channel;

class SocialiteManagerTest extends TestCase
{
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

    public function testSetConfigOverridesDriverCredentials()
    {
        $factory = $this->app->make(SocialiteManager::class);

        $provider = $factory->driver('github');
        $provider->stateless();
        $provider->setConfig([
            'client_id' => 'tenant-id',
            'client_secret' => 'tenant-secret',
            'redirect' => 'https://tenant.example.com/callback',
        ]);

        $response = $provider->redirect();

        $this->assertStringContainsString('client_id=tenant-id', $response->getTargetUrl());
        $this->assertStringContainsString('redirect_uri=' . urlencode('https://tenant.example.com/callback'), $response->getTargetUrl());
        $this->assertStringNotContainsString('github-client-id', $response->getTargetUrl());
    }

    public function testSameProviderClassWithDifferentDriversDoesNotCollide()
    {
        $this->app->make('config')
            ->set('services.github_a', [
                'client_id' => 'id_a',
                'client_secret' => 'secret_a',
                'redirect' => 'https://a.example.com/callback',
            ]);
        $this->app->make('config')
            ->set('services.github_b', [
                'client_id' => 'id_b',
                'client_secret' => 'secret_b',
                'redirect' => 'https://b.example.com/callback',
            ]);

        $factory = $this->app->make(SocialiteManager::class);

        $factory->extend('github_a', fn () => $factory->buildOAuth2Provider(
            GithubProvider::class,
            $this->app->make('config')->get('services.github_a')
        ));
        $factory->extend('github_b', fn () => $factory->buildOAuth2Provider(
            GithubProvider::class,
            $this->app->make('config')->get('services.github_b')
        ));

        $driverA = $factory->driver('github_a');
        $driverB = $factory->driver('github_b');

        $driverA->stateless();
        $driverB->stateless();

        $driverA->setConfig(['client_id' => 'tenant_a']);
        $driverB->setConfig(['client_id' => 'tenant_b']);

        $this->assertStringContainsString('client_id=tenant_a', $driverA->redirect()->getTargetUrl());
        $this->assertStringContainsString('client_id=tenant_b', $driverB->redirect()->getTargetUrl());
    }

    public function testConfigScopesSurviveAcrossCoroutines()
    {
        $this->app->make('config')
            ->set('services.github', [
                'client_id' => 'github-client-id',
                'client_secret' => 'github-client-secret',
                'redirect' => 'http://your-callback-url',
                'scopes' => ['read:user'],
            ]);

        $factory = $this->app->make(SocialiteManager::class);
        $provider = $factory->driver('github');

        $childScopes = null;
        $channel = new Channel(1);

        Coroutine::create(function () use ($provider, &$childScopes, $channel) {
            $childScopes = $provider->getScopes();
            $channel->push(true);
        });

        $channel->pop(1.0);

        // Config scopes merged with class default should survive into a fresh coroutine
        $this->assertSame(['user:email', 'read:user'], $childScopes);
    }

    public function testGenericProviderGetsRequestRefreshed()
    {
        $firstRequest = Request::create('/first');
        $secondRequest = Request::create('/second');

        $this->app->instance('request', $firstRequest);

        $factory = $this->app->make(SocialiteManager::class);

        $factory->extend('generic', fn () => new GenericTestProviderStub(
            $this->app->make('request')
        ));

        $provider = $factory->driver('generic');
        $this->assertSame($firstRequest, (new ReflectionProperty($provider, 'request'))->getValue($provider));

        $this->app->instance('request', $secondRequest);

        $provider = $factory->driver('generic');
        $this->assertSame($secondRequest, (new ReflectionProperty($provider, 'request'))->getValue($provider));
    }

    public function testSetContainerRefreshesConfig()
    {
        $factory = $this->app->make(SocialiteManager::class);

        $originalConfig = (new ReflectionProperty($factory, 'config'))->getValue($factory);

        // Create a new config repository with different values
        $newConfig = new Repository([
            'services' => [
                'github' => [
                    'client_id' => 'new-client-id',
                    'client_secret' => 'new-client-secret',
                    'redirect' => 'https://new.example.com/callback',
                ],
            ],
        ]);

        // Bind the new config and call setContainer
        $this->app->instance('config', $newConfig);
        $factory->setContainer($this->app);

        $refreshedConfig = (new ReflectionProperty($factory, 'config'))->getValue($factory);

        $this->assertNotSame($originalConfig, $refreshedConfig);
        $this->assertSame($newConfig, $refreshedConfig);

        // Verify the manager uses the new config for driver creation
        $factory->forgetDrivers();
        $provider = $factory->driver('github');
        $provider->stateless();

        $this->assertStringContainsString('client_id=new-client-id', $provider->redirect()->getTargetUrl());
    }
}
