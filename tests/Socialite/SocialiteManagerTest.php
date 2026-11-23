<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Hypervel\Config\Repository;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Socialite\Contracts\Factory;
use Hypervel\Socialite\Exceptions\DriverMissingConfigurationException;
use Hypervel\Socialite\SocialiteManager;
use Hypervel\Socialite\SocialiteServiceProvider;
use Hypervel\Socialite\Two\GithubProvider;
use Hypervel\Socialite\Two\GitlabProvider;
use Hypervel\Socialite\Two\XProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Socialite\Fixtures\OAuthTwoTestProviderStub;
use InvalidArgumentException;
use ReflectionProperty;
use Swoole\Coroutine\Channel;

use function Hypervel\Coroutine\parallel;

class SocialiteManagerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SocialiteServiceProvider::class];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')
            ->set('services.github', [
                'client_id' => 'github-client-id',
                'client_secret' => 'github-client-secret',
                'redirect' => 'http://your-callback-url',
            ]);
    }

    public function testRelativeRedirectUsesConfiguredOriginForAuthorizationAndTokenExchange(): void
    {
        config()->set('app.url', 'https://app.example.com/base/');
        config()->set('services.github.redirect', '/auth/github/callback?flow=login');
        RequestContext::set(Request::create('http://evil.example.com/start'));
        $provider = $this->app->make(Factory::class)->driver('github')->stateless();
        parse_str(parse_url($provider->redirect()->getTargetUrl(), PHP_URL_QUERY), $query);
        $expected = 'https://app.example.com/base/auth/github/callback?flow=login';
        $this->assertSame($expected, $query['redirect_uri']);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"access_token":"token"}'),
        ]));
        $stack->push(Middleware::history($history));
        $provider->setHttpClient(new Client(['handler' => $stack]));
        $provider->getAccessTokenResponse('code');
        parse_str((string) $history[0]['request']->getBody(), $form);
        $this->assertSame($expected, $form['redirect_uri']);
    }

    public function testNetworkPathRedirectCannotOverrideConfiguredOrigin(): void
    {
        config()->set('app.url', 'https://app.example.com');
        config()->set('services.github.redirect', fn () => '//evil.example.com/callback');
        $provider = $this->app->make(Factory::class)->driver('github')->stateless();
        parse_str(parse_url($provider->redirect()->getTargetUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('https://app.example.com/evil.example.com/callback', $query['redirect_uri']);
    }

    public function testAbsoluteRedirectIsPreserved(): void
    {
        config()->set('services.github.redirect', 'https://auth.example.com:8443/callback?q=1');
        $provider = $this->app->make(Factory::class)->driver('github')->stateless();
        parse_str(parse_url($provider->redirect()->getTargetUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('https://auth.example.com:8443/callback?q=1', $query['redirect_uri']);
    }

    public function testRelativeRedirectRejectsMissingTrustedOrigin(): void
    {
        config()->set('app.url', null);
        config()->set('services.github.redirect', '/callback');
        $provider = $this->app->make(Factory::class)->driver('github')->stateless();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('An absolute HTTP(S) app.url is required for relative Socialite redirect URLs.');

        // Unlike upstream, Hypervel formats the redirect when a request uses it, not when the driver is built.
        $provider->redirect();
    }

    // REMOVED: test_oauth_one_relative_callback_uses_configured_origin; OAuth 1 and formatConfig() are not supported.

    public function testItCanInstantiateTheGithubDriver(): void
    {
        $factory = $this->app->make(SocialiteManager::class);

        $provider = $factory->driver('github');

        $this->assertInstanceOf(GithubProvider::class, $provider);
    }

    public function testFactoryAndConcreteManagerShareOneDriverRegistry(): void
    {
        $factory = $this->app->make(Factory::class);
        $manager = $this->app->make(SocialiteManager::class);

        $this->assertSame($manager, $factory);

        $manager->extend('custom', static fn (Container $container) => new OAuthTwoTestProviderStub(
            $container->make('request'),
            'client_id',
            'client_secret',
            'redirect'
        ));

        $this->assertSame($manager->driver('custom'), $factory->driver('custom'));
    }

    public function testGitlabDriverUsesConfiguredHost(): void
    {
        $this->app->make('config')
            ->set('services.gitlab', [
                'client_id' => 'gitlab-client-id',
                'client_secret' => 'gitlab-client-secret',
                'redirect' => 'http://your-callback-url',
                'host' => 'https://gitlab.example.com/',
            ]);

        $factory = $this->app->make(SocialiteManager::class);

        $provider = $factory->driver('gitlab')->stateless();

        $this->assertStringStartsWith(
            'https://gitlab.example.com/oauth/authorize?',
            $provider->redirect()->getTargetUrl()
        );
    }

    public function testGitlabDriverFallsBackToDefaultHostWhenHostIsNull(): void
    {
        $this->app->make('config')
            ->set('services.gitlab', [
                'client_id' => 'gitlab-client-id',
                'client_secret' => 'gitlab-client-secret',
                'redirect' => 'http://your-callback-url',
                'host' => null,
            ]);

        $factory = $this->app->make(SocialiteManager::class);

        $provider = $factory->driver('gitlab')->stateless();

        $this->assertStringStartsWith(
            'https://gitlab.com/oauth/authorize?',
            $provider->redirect()->getTargetUrl()
        );
    }

    public function testGitlabHostOverrideIsIsolatedPerCoroutine(): void
    {
        $provider = new GitlabProvider(
            Request::create('/'),
            'client_id',
            'client_secret',
            'redirect'
        );

        [$urlA, $urlB] = parallel([
            function () use ($provider): string {
                $provider->stateless()->setHost('https://gitlab-a.example.com/');

                usleep(5000);

                return $provider->redirect()->getTargetUrl();
            },
            function () use ($provider): string {
                usleep(2500);

                return $provider
                    ->stateless()
                    ->setHost('https://gitlab-b.example.com/')
                    ->redirect()
                    ->getTargetUrl();
            },
        ]);

        $this->assertStringStartsWith('https://gitlab-a.example.com/oauth/authorize?', $urlA);
        $this->assertStringStartsWith('https://gitlab-b.example.com/oauth/authorize?', $urlB);
    }

    // REMOVED: Laravel Socialite's OAuth 1 and legacy Twitter manager tests do not apply;
    // Hypervel exposes X through OAuth 2 as the "x" driver.

    public function testXDriverUsesCanonicalConfiguration(): void
    {
        $this->app->make('config')->set('services.x', [
            'client_id' => 'x-client-id',
            'client_secret' => 'x-client-secret',
            'redirect' => 'https://example.com/x/callback',
        ]);

        $this->assertInstanceOf(
            XProvider::class,
            $this->app->make(SocialiteManager::class)->driver('x'),
        );
    }

    public function testXDriverRejectsLegacyOnlyConfiguration(): void
    {
        $this->app->make('config')->set([
            'services.x' => null,
            'services.x-oauth-2' => [
                'client_id' => 'legacy-client-id',
                'client_secret' => 'legacy-client-secret',
                'redirect' => 'https://example.com/x/callback',
            ],
        ]);

        $this->expectException(DriverMissingConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('Missing required configuration keys [client_id, client_secret, redirect]');

        $this->app->make(SocialiteManager::class)->driver('x');
    }

    public function testLegacyXDriverNamesRemainUnsupported(): void
    {
        $manager = $this->app->make(SocialiteManager::class);

        foreach (['twitter', 'x-oauth-2'] as $driver) {
            try {
                $manager->driver($driver);
                $this->fail("Expected the [{$driver}] driver to be unsupported.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame("Driver [{$driver}] not supported.", $exception->getMessage());
            }
        }
    }

    public function testCachedProviderResolvesClosureRedirectForEachExecution(): void
    {
        $this->app->make('config')->set('services.github.redirect', static fn (): string => request()->getSchemeAndHttpHost() . '/callback');
        RequestContext::set(Request::create('https://initial.example.com/login'));

        $manager = $this->app->make(SocialiteManager::class);
        $this->assertStringContainsString(
            urlencode('https://initial.example.com/callback'),
            $manager->driver('github')->stateless()->redirect()->getTargetUrl(),
        );

        RequestContext::set(Request::create('https://replacement.example.com/login'));
        $parentUrl = $manager->driver('github')->stateless()->redirect()->getTargetUrl();
        $this->assertStringContainsString(urlencode('https://replacement.example.com/callback'), $parentUrl);

        [$tenantAUrl, $tenantBUrl] = parallel([
            function () use ($manager): string {
                RequestContext::set(Request::create('https://tenant-a.example.com/login'));

                usleep(5000);

                return $manager->driver('github')->stateless()->redirect()->getTargetUrl();
            },
            function () use ($manager): string {
                RequestContext::set(Request::create('https://tenant-b.example.com/login'));

                return $manager->driver('github')->stateless()->redirect()->getTargetUrl();
            },
        ], copyContext: true);

        $this->assertStringContainsString(urlencode('https://tenant-a.example.com/callback'), $tenantAUrl);
        $this->assertStringContainsString(urlencode('https://tenant-b.example.com/callback'), $tenantBUrl);
        $this->assertSame($parentUrl, $manager->driver('github')->stateless()->redirect()->getTargetUrl());
    }

    public function testBuildOAuth2ProviderPreservesRedirectFormatterExtensionPoint(): void
    {
        $manager = new RedirectFormattingSocialiteManager($this->app);
        $provider = $manager->buildOAuth2Provider(GithubProvider::class, [
            'client_id' => 'github-client-id',
            'client_secret' => 'github-client-secret',
            'redirect' => '/callback',
        ])->stateless();

        $this->assertStringContainsString(
            'redirect_uri=' . urlencode('https://formatted.example.com/callback'),
            $provider->redirect()->getTargetUrl(),
        );
    }

    public function testItCanInstantiateTheGithubDriverWithScopesFromConfigArray(): void
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

    public function testItCanInstantiateTheGithubDriverWithScopesWithoutArrayFromConfig(): void
    {
        $factory = $this->app->make(SocialiteManager::class);
        $provider = $factory->driver('github');
        $this->assertSame(['user:email'], $provider->getScopes());
    }

    public function testItCanInstantiateTheGithubDriverWithScopesFromConfigArrayMergedByProgrammaticScopesUsingScopesMethod(): void
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

    public function testItCanInstantiateTheGithubDriverWithScopesFromConfigArrayOverwrittenByProgrammaticScopesUsingSetScopesMethod(): void
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

    public function testItThrowsExceptionWhenClientSecretIsMissing(): void
    {
        $this->expectException(DriverMissingConfigurationException::class);
        $this->expectExceptionMessageIs('Missing required configuration keys [client_secret] for [Hypervel\Socialite\Two\GithubProvider] OAuth provider.');

        $factory = $this->app->make(SocialiteManager::class);

        $this->app->make('config')
            ->set('services.github', [
                'client_id' => 'github-client-id',
                'redirect' => 'http://your-callback-url',
            ]);

        $factory->driver('github');
    }

    public function testItThrowsExceptionWhenClientIdIsMissing(): void
    {
        $this->expectException(DriverMissingConfigurationException::class);
        $this->expectExceptionMessageIs('Missing required configuration keys [client_id] for [Hypervel\Socialite\Two\GithubProvider] OAuth provider.');

        $factory = $this->app->make(SocialiteManager::class);

        $this->app->make('config')
            ->set('services.github', [
                'client_secret' => 'github-client-secret',
                'redirect' => 'http://your-callback-url',
            ]);

        $factory->driver('github');
    }

    public function testItThrowsExceptionWhenRedirectIsMissing(): void
    {
        $this->expectException(DriverMissingConfigurationException::class);
        $this->expectExceptionMessageIs('Missing required configuration keys [redirect] for [Hypervel\Socialite\Two\GithubProvider] OAuth provider.');

        $factory = $this->app->make(SocialiteManager::class);

        $this->app->make('config')
            ->set('services.github', [
                'client_id' => 'github-client-id',
                'client_secret' => 'github-client-secret',
            ]);

        $factory->driver('github');
    }

    public function testItThrowsExceptionWhenConfigurationIsCompletelyMissing(): void
    {
        $this->expectException(DriverMissingConfigurationException::class);
        $this->expectExceptionMessageIs('Missing required configuration keys [client_id, client_secret, redirect] for [Hypervel\Socialite\Two\GithubProvider] OAuth provider.');

        $factory = $this->app->make(SocialiteManager::class);

        $this->app->make('config')
            ->set('services.github', null);

        $factory->driver('github');
    }

    public function testSetConfigOverridesDriverCredentials(): void
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

    public function testSameProviderClassWithDifferentDriversDoesNotCollide(): void
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

        $factory->extend('github_a', static fn (Container $container) => $factory->buildOAuth2Provider(
            GithubProvider::class,
            $container->make('config')->get('services.github_a')
        ));
        $factory->extend('github_b', static fn (Container $container) => $factory->buildOAuth2Provider(
            GithubProvider::class,
            $container->make('config')->get('services.github_b')
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

    public function testConfigScopesSurviveAcrossCoroutines(): void
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

    public function testCachedProviderGetsRequestRefreshed(): void
    {
        $firstRequest = Request::create('/first');
        $secondRequest = Request::create('/second');

        RequestContext::set($firstRequest);

        $factory = $this->app->make(SocialiteManager::class);

        $factory->extend('generic', static fn (Container $container) => new OAuthTwoTestProviderStub(
            $container->make('request'),
            'client_id',
            'client_secret',
            'redirect'
        ));

        $provider = $factory->driver('generic');
        $this->assertSame($firstRequest, $provider->getProviderRequest());

        RequestContext::set($secondRequest);

        $provider = $factory->driver('generic');
        $this->assertSame($secondRequest, $provider->getProviderRequest());
    }

    public function testSetContainerRefreshesConfig(): void
    {
        $factory = $this->app->make(SocialiteManager::class);

        $originalConfig = (new ReflectionProperty($factory, 'config'))->getValue($factory);

        // Create a new config repository with different service values
        $newConfig = new Repository($this->app->make('config')->all());
        $newConfig->set('services.github', [
            'client_id' => 'new-client-id',
            'client_secret' => 'new-client-secret',
            'redirect' => 'https://new.example.com/callback',
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

class RedirectFormattingSocialiteManager extends SocialiteManager
{
    protected function formatRedirectUrl(array $config): string
    {
        return 'https://formatted.example.com' . value($config['redirect']);
    }
}
