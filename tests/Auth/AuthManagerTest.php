<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Closure;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\AuthManager;
use Hypervel\Auth\DatabaseUserProvider;
use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\RedirectIfAuthenticated;
use Hypervel\Auth\RequestGuard;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\RedisStore;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Hashing\Hasher as HashContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Foundation\Auth\User as FoundationUser;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Auth as AuthFacade;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use ReflectionClass;

class AuthManagerTest extends TestCase
{
    public function testGetDefaultDriverFromConfig()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.defaults.guard', 'foo');

        $this->assertSame('foo', $manager->getDefaultDriver());
    }

    public function testGetDefaultDriverFromContext()
    {
        $manager = new AuthManager($this->getContainer());

        CoroutineContext::set(AuthManager::DEFAULT_GUARD_CONTEXT_KEY, 'foo');

        Coroutine::create(function () use ($manager) {
            CoroutineContext::set(AuthManager::DEFAULT_GUARD_CONTEXT_KEY, 'bar');

            $this->assertSame('bar', $manager->getDefaultDriver());
        });

        $this->assertSame('foo', $manager->getDefaultDriver());
    }

    public function testGetDefaultDriverAcceptsFalseyContextValue(): void
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.defaults.guard', 'foo');

        CoroutineContext::set(AuthManager::DEFAULT_GUARD_CONTEXT_KEY, '0');

        $this->assertSame('0', $manager->getDefaultDriver());
    }

    public function testSetDefaultDriverUsesContext()
    {
        $manager = new AuthManager($this->getContainer());

        $manager->setDefaultDriver('api');

        $this->assertSame('api', $manager->getDefaultDriver());
        $this->assertSame('api', CoroutineContext::get(AuthManager::DEFAULT_GUARD_CONTEXT_KEY));
    }

    public function testShouldUseSetsDefaultDriverAndUserResolver()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.defaults.guard', 'web');
        $container->make('config')
            ->set('auth.guards.api', ['driver' => 'custom']);

        $guard = m::mock(Guard::class);
        $manager->extend('custom', function () use ($guard) {
            return $guard;
        });

        $manager->shouldUse('api');

        $this->assertSame('api', $manager->getDefaultDriver());
        // The user resolver should have been updated
        $this->assertInstanceOf(Closure::class, $manager->userResolver());
    }

    public function testShouldUseAcceptsFalseyGuardName(): void
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.defaults.guard', 'foo');

        $manager->shouldUse('0');

        $this->assertSame('0', $manager->getDefaultDriver());
    }

    public function testExtendDriver()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.guards.foo', ['driver' => 'bar']);
        $guard = m::mock(Guard::class);

        $manager->extend('bar', function () use ($guard) {
            return $guard;
        });

        $this->assertSame($guard, $manager->guard('foo'));
    }

    public function testGuardWithExplicitFalseyNameDoesNotFallBackToDefaultDriver(): void
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.defaults.guard', 'foo');
        $container->make('config')
            ->set('auth.guards.0', ['driver' => 'bar']);
        $container->make('config')
            ->set('auth.guards.foo', ['driver' => 'bar']);

        $guard = m::mock(Guard::class);
        $test = $this;
        $manager->extend('bar', function ($app, string $name) use ($guard, $test): Guard {
            $test->assertSame('0', $name);

            return $guard;
        });

        $this->assertSame($guard, $manager->guard('0'));
    }

    public function testExtendCallbackIsBoundToManager()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.guards.foo', ['driver' => 'bar']);

        $boundTo = null;
        $manager->extend('bar', function () use (&$boundTo) {
            $boundTo = $this;

            return m::mock(Guard::class);
        });

        $manager->guard('foo');

        $this->assertSame($manager, $boundTo);
    }

    public function testCreateUserProviderReturnsNullWhenNoProviderIsConfigured(): void
    {
        $manager = new AuthManager($this->getContainer());

        $this->assertNull($manager->createUserProvider());
    }

    public function testGetDefaultUserProviderUsesCurrentDefaultGuard(): void
    {
        $manager = new AuthManager($container = $this->getContainer());
        $config = $container->make('config');
        $config->set('auth.defaults.guard', 'web');
        $config->set('auth.guards.web.provider', 'users');
        $config->set('auth.guards.admin.provider', 'admins');

        $this->assertSame('users', $manager->getDefaultUserProvider());

        $manager->shouldUse('admin');

        $this->assertSame('admins', $manager->getDefaultUserProvider());
    }

    public function testGetDefaultUserProviderReturnsNullWhenCurrentGuardHasNoProvider(): void
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')->set('auth.defaults.guard', 'api');
        $container->make('config')->set('auth.guards.api', ['driver' => 'token']);

        $this->assertNull($manager->getDefaultUserProvider());
    }

    public function testCreateNullUserProvider()
    {
        $manager = new AuthManager($this->getContainer());

        $this->assertNull($manager->createUserProvider('foo'));
    }

    public function testCreateDatabaseUserProvider()
    {
        $manager = new AuthManager($container = $this->getContainer());

        $container->make('config')
            ->set('auth.providers.foo', [
                'driver' => 'database',
                'connection' => 'foo',
                'table' => 'users',
            ]);

        $db = m::mock();
        $db->shouldReceive('connection')
            ->with('foo')
            ->once()
            ->andReturn(m::mock(ConnectionInterface::class));

        $container->instance('db', $db);
        $container->instance('hash', m::mock(HashContract::class));

        $this->assertInstanceOf(
            DatabaseUserProvider::class,
            $manager->createUserProvider('foo')
        );
    }

    public function testCreateCustomUserProvider()
    {
        $manager = new AuthManager($container = $this->getContainer());

        $container->make('config')
            ->set('auth.providers.foo', [
                'driver' => 'bar',
            ]);

        $provider = m::mock(UserProvider::class);
        $manager->provider('bar', fn () => $provider);

        $this->assertSame($provider, $manager->createUserProvider('foo'));
    }

    public function testGetUserResolverIsolatedPerCoroutine()
    {
        $manager = new AuthManager($this->getContainer());

        $manager->resolveUsersUsing(fn () => 'foo');

        Coroutine::create(function () use ($manager) {
            $manager->resolveUsersUsing(fn () => 'bar');

            $this->assertSame('bar', $manager->userResolver()());
        });

        $this->assertSame('foo', $manager->userResolver()());
    }

    public function testViaRequest(): void
    {
        $manager = new AuthManager($this->app);
        RequestContext::set(Request::create('/'));

        $this->app->make('config')
            ->set('auth.providers.foo', [
                'driver' => 'foo',
            ]);
        $this->app->make('config')
            ->set('auth.guards.foo', [
                'driver' => 'custom',
                'provider' => 'foo',
            ]);

        $provider = m::mock(UserProvider::class);
        $manager->provider('foo', fn () => $provider);

        $user = m::mock(Authenticatable::class);
        $manager->viaRequest('custom', function (Request $request, ?UserProvider $requestProvider) use ($provider, $user) {
            $this->assertSame($provider, $requestProvider);

            return $user;
        });

        $this->assertInstanceOf(RequestGuard::class, $guard = $manager->guard('foo'));
        $this->assertSame($provider, $guard->getProvider());
        $this->assertSame($user, $guard->user());
    }

    public function testViaRequestGuardWithoutProviderKeyGetsNullProvider(): void
    {
        $manager = new AuthManager($this->app);
        RequestContext::set(Request::create('/'));

        $this->app->make('config')
            ->set('auth.guards.foo', [
                'driver' => 'custom',
            ]);

        $user = m::mock(Authenticatable::class);
        $manager->viaRequest('custom', function (Request $request, ?UserProvider $requestProvider) use ($user) {
            $this->assertNull($requestProvider);

            return $user;
        });

        $this->assertInstanceOf(RequestGuard::class, $guard = $manager->guard('foo'));
        $this->assertNull($guard->getProvider());
        $this->assertSame($user, $guard->user());
    }

    public function testRedirectGuestsToConfiguresAuthenticateMiddleware(): void
    {
        $manager = new AuthManager($this->getContainer());

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('check')->andReturnFalse();

        $factory = m::mock(AuthFactory::class);
        $factory->shouldReceive('guard')->with(null)->andReturn($guard);

        $manager->redirectGuestsTo('/login');

        // Isolate Authenticate's own slot so this test fails if the aggregate
        // stops configuring the auth-middleware redirect directly.
        AuthenticationException::flushState();

        try {
            (new Authenticate($factory))->handle(Request::create('/secret'), fn () => null);
        } catch (AuthenticationException $exception) {
            $this->assertSame('/login', $exception->redirectTo(Request::create('/secret')));

            return;
        }

        $this->fail('AuthenticationException was not thrown.');
    }

    public function testRedirectGuestsToConfiguresAuthenticationExceptionFallbackPerRequest(): void
    {
        $manager = new AuthManager($this->getContainer());

        $manager->redirectGuestsTo(fn (Request $request) => $request->headers->get('X-Tenant') === 'admin'
            ? '/admin/login'
            : '/login');

        $this->assertSame(
            '/admin/login',
            (new AuthenticationException)->redirectTo(Request::create('/', server: ['HTTP_X_TENANT' => 'admin']))
        );

        $this->assertSame(
            '/login',
            (new AuthenticationException)->redirectTo(Request::create('/'))
        );
    }

    public function testRedirectGuestsToCanBeConfiguredThroughFacade(): void
    {
        AuthFacade::swap(new AuthManager($this->getContainer()));

        AuthFacade::redirectGuestsTo('/facade-login');

        $this->assertSame('/facade-login', (new AuthenticationException)->redirectTo(Request::create('/')));
    }

    public function testRedirectGuestsToAcceptsNull(): void
    {
        $manager = new AuthManager($this->getContainer());

        $manager->redirectGuestsTo(null);

        $this->assertNull((new AuthenticationException)->redirectTo(Request::create('/')));
    }

    public function testRedirectGuestsToNullCanBeConfiguredThroughFacade(): void
    {
        AuthFacade::swap(new AuthManager($this->getContainer()));

        AuthFacade::redirectGuestsTo(null);

        $this->assertNull((new AuthenticationException)->redirectTo(Request::create('/')));
    }

    public function testRedirectUsersToConfiguresRedirectIfAuthenticatedMiddlewarePerRequest(): void
    {
        $manager = new AuthManager($this->getContainer());
        $manager->redirectUsersTo(fn (Request $request) => $request->headers->get('X-Tenant') === 'admin'
            ? '/admin'
            : '/dashboard');

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('check')->andReturnTrue();

        $factory = m::mock(AuthFactory::class);
        $factory->shouldReceive('guard')->andReturn($guard);
        AuthFacade::swap($factory);

        $response = (new RedirectIfAuthenticated)->handle(
            Request::create('/login', server: ['HTTP_X_TENANT' => 'admin']),
            fn () => null
        );

        $this->assertStringContainsString('/admin', $response->headers->get('Location'));
    }

    public function testRedirectToConfiguresBothRedirectSides(): void
    {
        $manager = new AuthManager($this->getContainer());

        $manager->redirectTo(guests: '/login', users: '/dashboard');

        $this->assertSame('/login', (new AuthenticationException)->redirectTo(Request::create('/')));

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('check')->andReturnTrue();

        $factory = m::mock(AuthFactory::class);
        $factory->shouldReceive('guard')->andReturn($guard);
        AuthFacade::swap($factory);

        $response = (new RedirectIfAuthenticated)->handle(Request::create('/login'), fn () => null);

        $this->assertStringContainsString('/dashboard', $response->headers->get('Location'));
    }

    public function testRedirectToNullLeavesGuestRedirectUnchanged(): void
    {
        $manager = new AuthManager($this->getContainer());

        $manager->redirectGuestsTo('/login');
        $manager->redirectTo(guests: null);

        $this->assertSame('/login', (new AuthenticationException)->redirectTo(Request::create('/')));
    }

    public function testRedirectConfigurationUsesMostRecentHighLevelRegistration(): void
    {
        $manager = new AuthManager($this->getContainer());

        $manager->redirectGuestsTo('/first-login');
        $manager->redirectGuestsTo('/second-login');

        $this->assertSame('/second-login', (new AuthenticationException)->redirectTo(Request::create('/')));

        $manager->redirectUsersTo('/first-dashboard');
        $manager->redirectUsersTo('/second-dashboard');

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('check')->andReturnTrue();

        $factory = m::mock(AuthFactory::class);
        $factory->shouldReceive('guard')->andReturn($guard);
        AuthFacade::swap($factory);

        $response = (new RedirectIfAuthenticated)->handle(Request::create('/login'), fn () => null);

        $this->assertStringContainsString('/second-dashboard', $response->headers->get('Location'));
    }

    public function testGuardCachesResolvedInstances()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.guards.foo', ['driver' => 'bar']);

        $manager->extend('bar', fn () => m::mock(Guard::class));

        $guard1 = $manager->guard('foo');
        $guard2 = $manager->guard('foo');

        $this->assertSame($guard1, $guard2);
    }

    public function testHasResolvedGuardsReturnsFalseWhenEmpty()
    {
        $manager = new AuthManager($this->getContainer());

        $this->assertFalse($manager->hasResolvedGuards());
    }

    public function testHasResolvedGuardsReturnsTrueAfterResolving()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.guards.foo', ['driver' => 'bar']);

        $manager->extend('bar', fn () => m::mock(Guard::class));
        $manager->guard('foo');

        $this->assertTrue($manager->hasResolvedGuards());
    }

    public function testForgetGuardsClearsCache()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.guards.foo', ['driver' => 'bar']);

        $manager->extend('bar', fn () => m::mock(Guard::class));
        $manager->guard('foo');

        $this->assertTrue($manager->hasResolvedGuards());

        $manager->forgetGuards();

        $this->assertFalse($manager->hasResolvedGuards());
        $this->assertEmpty($manager->getGuards());
    }

    public function testGetGuardsReturnsAllResolved()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.guards.foo', ['driver' => 'bar']);
        $container->make('config')
            ->set('auth.guards.baz', ['driver' => 'bar']);

        $manager->extend('bar', fn () => m::mock(Guard::class));
        $manager->guard('foo');
        $manager->guard('baz');

        $guards = $manager->getGuards();

        $this->assertCount(2, $guards);
        $this->assertArrayHasKey('foo', $guards);
        $this->assertArrayHasKey('baz', $guards);
    }

    public function testSetApplicationReplacesContainer()
    {
        $manager = new AuthManager($container1 = $this->getContainer());
        $container2 = $this->getContainer();
        $container2->make('config')->set('auth.defaults.guard', 'api');

        $manager->setApplication($container2);

        $this->assertSame('api', $manager->getDefaultDriver());
    }

    public function testResolveThrowsForUndefinedGuard()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth guard [missing] is not defined.');

        $manager = new AuthManager($this->getContainer());
        $manager->guard('missing');
    }

    public function testResolveThrowsForUndefinedDriver()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth driver [unknown] for guard [foo] is not defined.');

        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.guards.foo', ['driver' => 'unknown']);

        $manager->guard('foo');
    }

    public function testMagicCallDelegatesToDefaultGuard()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->make('config')
            ->set('auth.defaults.guard', 'foo');
        $container->make('config')
            ->set('auth.guards.foo', ['driver' => 'bar']);

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('check')->once()->andReturn(true);

        $manager->extend('bar', fn () => $guard);

        $this->assertTrue($manager->check());
    }

    public function testClearUserCacheIsNoOpForCustomGuardWithoutGetProvider()
    {
        $manager = new AuthManager($container = $this->getContainer([
            'guards' => [
                'api' => ['driver' => 'custom'],
            ],
        ]));

        $guard = m::mock(Guard::class);
        $manager->extend('custom', fn () => $guard);

        $manager->clearUserCache(42, 'api');

        $this->addToAssertionCount(1);
    }

    public function testClearUserCacheUsesSpecifiedGuardProvider()
    {
        $manager = new AuthManager($container = $this->getContainer([
            'defaults' => [
                'guard' => 'web',
            ],
            'guards' => [
                'web' => ['driver' => 'token', 'provider' => 'users'],
                'admin' => ['driver' => 'token', 'provider' => 'admins'],
            ],
            'providers' => [
                'users' => [
                    'driver' => 'eloquent',
                    'model' => AuthManagerCacheUserStub::class,
                    'cache' => ['enabled' => true, 'store' => 'web-store'],
                ],
                'admins' => [
                    'driver' => 'eloquent',
                    'model' => AuthManagerCacheAdminStub::class,
                    'cache' => ['enabled' => true, 'store' => 'admin-store', 'prefix' => 'admin_users'],
                ],
            ],
        ]));

        Container::setInstance($container);
        $container->instance('hash', m::mock(HashContract::class));

        $cacheManager = m::mock(CacheManager::class);
        $adminRepo = m::mock(CacheRepository::class);
        $adminRepo->shouldReceive('getStore')->andReturn(m::mock(RedisStore::class));
        $adminRepo->shouldReceive('forget')
            ->once()
            ->with('admin_users:' . AuthManagerCacheAdminStub::class . ':42')
            ->andReturn(true);
        $cacheManager->shouldReceive('store')->with('admin-store')->andReturn($adminRepo);
        $container->instance('cache', $cacheManager);

        $manager->clearUserCache(42, 'admin');
    }

    public function testClearUserCacheUsesDefaultGuardAndRespectsResolver()
    {
        $manager = new AuthManager($container = $this->getContainer([
            'defaults' => [
                'guard' => 'web',
            ],
            'guards' => [
                'web' => ['driver' => 'token', 'provider' => 'users'],
            ],
            'providers' => [
                'users' => [
                    'driver' => 'eloquent',
                    'model' => AuthManagerCacheUserStub::class,
                    'cache' => ['enabled' => true, 'store' => 'web-store'],
                ],
            ],
        ]));

        Container::setInstance($container);
        $container->instance('hash', m::mock(HashContract::class));

        $cacheManager = m::mock(CacheManager::class);
        $repo = m::mock(CacheRepository::class);
        $repo->shouldReceive('getStore')->andReturn(m::mock(RedisStore::class));
        $repo->shouldReceive('forget')
            ->once()
            ->with('auth_users:' . AuthManagerCacheUserStub::class . ':tenant:42')
            ->andReturn(true);
        $cacheManager->shouldReceive('store')->with('web-store')->andReturn($repo);
        $container->instance('cache', $cacheManager);

        EloquentUserProvider::resolveUserCacheKeyUsing(fn (mixed $identifier): string => 'tenant:' . $identifier);

        $manager->clearUserCache(42);
    }

    public function testForgetGuardsDoesNotAccumulateAuthCacheDescriptors()
    {
        $manager = new AuthManager($container = $this->getContainer([
            'defaults' => [
                'guard' => 'api',
            ],
            'guards' => [
                'api' => ['driver' => 'token', 'provider' => 'users'],
            ],
            'providers' => [
                'users' => [
                    'driver' => 'eloquent',
                    'model' => AuthManagerCacheUserStub::class,
                    'cache' => ['enabled' => true, 'store' => 'redis'],
                ],
            ],
        ]));

        Container::setInstance($container);
        $container->instance('hash', m::mock(HashContract::class));

        $cacheManager = m::mock(CacheManager::class);
        $firstRepo = m::mock(CacheRepository::class);
        $firstRepo->shouldReceive('getStore')->andReturn(m::mock(RedisStore::class));
        $secondRepo = m::mock(CacheRepository::class);
        $secondRepo->shouldReceive('getStore')->andReturn(m::mock(RedisStore::class));
        $cacheManager->shouldReceive('store')->with('redis')->andReturn($firstRepo, $secondRepo);
        $container->instance('cache', $cacheManager);

        $firstGuard = $manager->guard('api');

        $manager->forgetGuards();

        $secondGuard = $manager->guard('api');

        $this->assertNotSame($firstGuard, $secondGuard);

        $reflection = new ReflectionClass(EloquentUserProvider::class);
        $descriptors = $reflection->getStaticPropertyValue('cachedProviders');

        $this->assertArrayHasKey(AuthManagerCacheUserStub::class, $descriptors);
        $this->assertCount(1, $descriptors[AuthManagerCacheUserStub::class]);
    }

    protected function getContainer(array $authConfig = []): Container
    {
        $container = new Container;
        $container->instance('config', new Repository([
            'auth' => $authConfig,
        ]));

        return $container;
    }
}

class AuthManagerCacheUserStub extends FoundationUser
{
}

class AuthManagerCacheAdminStub extends FoundationUser
{
}
