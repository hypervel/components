<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hyperf\Config\Config;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Coroutine\Coroutine;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Auth\Guards\RequestGuard;
use Hypervel\Auth\Providers\DatabaseUserProvider;
use Hypervel\Context\ApplicationContext;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Contracts\Hashing\Hasher as HashContract;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class AuthMangerTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testGetDefaultDriverFromConfig()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->get(ConfigInterface::class)
            ->set('auth.defaults.guard', 'foo');

        $this->assertSame('foo', $manager->getDefaultDriver());
    }

    public function testGetDefaultDriverFromContext()
    {
        $manager = new AuthManager($this->getContainer());

        Context::set('__auth.defaults.guard', 'foo');

        Coroutine::create(function () use ($manager) {
            Context::set('__auth.defaults.guard', 'bar');

            $this->assertSame('bar', $manager->getDefaultDriver());
        });

        $this->assertSame('foo', $manager->getDefaultDriver());
    }

    public function testExtendDriver()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->get(ConfigInterface::class)
            ->set('auth.guards.foo', ['driver' => 'bar']);
        $guard = m::mock(Guard::class);

        $manager->extend('bar', function () use ($guard) {
            return $guard;
        });

        $this->assertSame($guard, $manager->guard('foo'));
    }

    public function testGetDefaultUserProvider()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->get(ConfigInterface::class)
            ->set('auth.defaults.provider', 'foo');

        $this->assertSame('foo', $manager->getDefaultUserProvider());
    }

    public function testCreateNullUserProvider()
    {
        $manager = new AuthManager($this->getContainer());

        $this->assertNull($manager->createUserProvider('foo'));
    }

    public function testCreateDatabaseUserProvider()
    {
        $manager = new AuthManager($container = $this->getContainer());

        $container->get(ConfigInterface::class)
            ->set('auth.providers.foo', [
                'driver' => 'database',
                'connection' => 'foo',
                'table' => 'foo',
            ]);

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')
            ->with('foo')
            ->once()
            ->andReturn(m::mock(ConnectionInterface::class));

        $container->define(ConnectionResolverInterface::class, fn () => $resolver);
        $container->define(HashContract::class, fn () => m::mock(HashContract::class));

        $this->assertInstanceOf(
            DatabaseUserProvider::class,
            $manager->createUserProvider('foo')
        );
    }

    public function testCreateCustomUserProvider()
    {
        $manager = new AuthManager($container = $this->getContainer());

        $container->get(ConfigInterface::class)
            ->set('auth.providers.foo', [
                'driver' => 'bar',
            ]);

        $provider = m::mock(UserProvider::class);
        $manager->provider('bar', fn () => $provider);

        $this->assertSame($provider, $manager->createUserProvider('foo'));
    }

    public function testGetUserResolver()
    {
        $manager = new AuthManager($this->getContainer());

        $manager->resolveUsersUsing(fn () => 'foo');

        Coroutine::create(function () use ($manager) {
            $manager->resolveUsersUsing(fn () => 'bar');

            $this->assertSame('bar', $manager->userResolver()());
        });

        $this->assertSame('foo', $manager->userResolver()());
    }

    public function testViaRequest()
    {
        $manager = new AuthManager($container = $this->getContainer());
        $container->set(RequestInterface::class, m::mock(RequestInterface::class));

        ApplicationContext::setContainer($container);

        $container->get(ConfigInterface::class)
            ->set('auth.providers.foo', [
                'driver' => 'foo',
            ]);
        $container->get(ConfigInterface::class)
            ->set('auth.guards.foo', [
                'driver' => 'custom',
            ]);
        $container->get(ConfigInterface::class)
            ->set('auth.defaults.provider', 'foo');

        $provider = m::mock(UserProvider::class);
        $manager->provider('foo', fn () => $provider);

        $user = m::mock(Authenticatable::class);
        $manager->viaRequest('custom', fn () => $user);

        $this->assertInstanceOf(RequestGuard::class, $guard = $manager->guard('foo'));
        $this->assertSame($user, $guard->user());
    }

    protected function getContainer(array $authConfig = [])
    {
        $config = new Config([
            'auth' => $authConfig,
        ]);

        return new Container(
            new DefinitionSource([
                ConfigInterface::class => fn () => $config,
            ])
        );
    }
}
