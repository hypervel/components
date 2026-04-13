<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Session\DatabaseSessionHandler;
use Hypervel\Session\SessionManager;
use Hypervel\Session\Store;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @internal
 * @coversNothing
 */
class SessionManagerTest extends TestCase
{
    public function testDatabaseDriverLeavesConnectionUnsetByDefault(): void
    {
        $manager = new SessionManager($this->getContainer([
            'session.driver' => 'database',
            'session.connection' => null,
            'session.table' => 'sessions',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
        ]));

        $store = $manager->driver();

        $this->assertInstanceOf(Store::class, $store);
        $this->assertInstanceOf(DatabaseSessionHandler::class, $this->handlerFromStore($store));
        $this->assertNull($this->databaseConnectionFromHandler($this->handlerFromStore($store)));
    }

    public function testRedisDriverDefaultsToSessionConnectionWhenUnset(): void
    {
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('setConnection')->once()->with('session');

        $repository = new CacheRepository($store);

        $cacheManager = m::mock();
        $cacheManager->shouldReceive('store')->with('redis')->andReturn($repository);

        $container = $this->getContainer([
            'session.driver' => 'redis',
            'session.connection' => null,
            'session.store' => null,
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
        ]);

        $container->instance('cache', $cacheManager);

        $sessionStore = (new SessionManager($container))->driver();

        $this->assertInstanceOf(Store::class, $sessionStore);
    }

    public function testExplicitSessionConnectionOverridesBothDrivers(): void
    {
        $databaseManager = new SessionManager($this->getContainer([
            'session.driver' => 'database',
            'session.connection' => 'custom-session',
            'session.table' => 'sessions',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
        ]));

        $databaseStore = $databaseManager->driver();

        $this->assertSame(
            'custom-session',
            $this->databaseConnectionFromHandler($this->handlerFromStore($databaseStore))
        );

        $redisStore = m::mock(RedisStore::class);
        $redisStore->shouldReceive('setConnection')->once()->with('custom-session');

        $repository = new CacheRepository($redisStore);

        $cacheManager = m::mock();
        $cacheManager->shouldReceive('store')->with('redis')->andReturn($repository);

        $container = $this->getContainer([
            'session.driver' => 'redis',
            'session.connection' => 'custom-session',
            'session.store' => null,
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
        ]);

        $container->instance('cache', $cacheManager);

        $this->assertInstanceOf(Store::class, (new SessionManager($container))->driver());
    }

    protected function getContainer(array $config): Container
    {
        $container = new Container;
        $container->instance(ContainerContract::class, $container);
        $container->instance('config', new ConfigRepository($config));
        $container->instance(Encrypter::class, m::mock(Encrypter::class));
        $container->instance('db', m::mock(ConnectionResolverInterface::class));

        Container::setInstance($container);

        return $container;
    }

    protected function handlerFromStore(Store $store): object
    {
        $property = new ReflectionProperty($store, 'handler');

        return $property->getValue($store);
    }

    protected function databaseConnectionFromHandler(DatabaseSessionHandler $handler): ?string
    {
        $property = new ReflectionProperty($handler, 'connection');

        return $property->getValue($handler);
    }
}
