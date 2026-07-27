<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Session\CacheBasedSessionHandler;
use Hypervel\Session\DatabaseSessionHandler;
use Hypervel\Session\EncryptedStore;
use Hypervel\Session\SessionManager;
use Hypervel\Session\Store;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use ReflectionProperty;
use SessionHandlerInterface;

class SessionManagerTest extends TestCase
{
    public function testEnumDefaultDriverIsNormalizedWithoutTreatingZeroAsAbsent(): void
    {
        $manager = new SessionManager($this->getContainer([
            'session' => [
                'driver' => 'array',
                'lifetime' => 120,
                'cookie' => 'session',
                'encrypt' => false,
                'serialization' => 'php',
            ],
        ]));

        $manager->extend('0', fn () => m::mock(SessionHandlerInterface::class));
        $manager->setDefaultDriver(SessionIntegerIdentifier::Zero);

        $this->assertSame('0', $manager->getDefaultDriver());
        $this->assertSame($manager->driver('0'), $manager->driver());
    }

    public function testDatabaseDriverLeavesConnectionUnsetByDefault(): void
    {
        $manager = new SessionManager($this->getContainer([
            'session.driver' => 'database',
            'session.connection' => null,
            'session.table' => 'sessions',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
            'session.serialization' => 'php',
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
        $store->shouldReceive('setPrefix')->once()->with('application_session:');

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
            'session.serialization' => 'php',
            'session.prefix' => 'application_session:',
        ]);

        $container->instance('cache', $cacheManager);

        $sessionStore = (new SessionManager($container))->driver();

        $this->assertInstanceOf(Store::class, $sessionStore);
    }

    public function testCacheBackedSessionsPreserveZeroStoreAndEmptyFallback(): void
    {
        foreach ([['0', '0'], ['', 'redis']] as [$configuredStore, $expectedStore]) {
            $store = m::mock(RedisStore::class);
            $store->shouldReceive('setConnection')->once()->with('session');

            $cacheManager = m::mock();
            $cacheManager->shouldReceive('store')
                ->once()
                ->with($expectedStore)
                ->andReturn(new CacheRepository($store));

            $container = $this->getContainer([
                'session.driver' => 'redis',
                'session.connection' => null,
                'session.store' => $configuredStore,
                'session.lifetime' => 120,
                'session.cookie' => 'session',
                'session.encrypt' => false,
                'session.serialization' => 'php',
                'session.prefix' => null,
            ]);
            $container->instance('cache', $cacheManager);

            $this->assertInstanceOf(Store::class, (new SessionManager($container))->driver());
        }
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
            'session.serialization' => 'php',
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
            'session.serialization' => 'php',
            'session.prefix' => null,
        ]);

        $container->instance('cache', $cacheManager);

        $this->assertInstanceOf(Store::class, (new SessionManager($container))->driver());
    }

    public function testRedisDriverAppliesSessionPrefixWithoutMutatingSharedCacheStore(): void
    {
        foreach ([
            ['custom:', 'custom:'],
            ['0', '0'],
            [null, 'cache:'],
            ['', 'cache:'],
        ] as [$configuredPrefix, $expectedPrefix]) {
            $redis = m::mock(RedisFactory::class);
            $sharedStore = new RedisStore($redis, 'cache:', 'cache');
            $repository = new CacheRepository($sharedStore);
            $cacheManager = m::mock();
            $cacheManager->shouldReceive('store')->once()->with('redis')->andReturn($repository);

            $container = $this->getContainer([
                'session.driver' => 'redis',
                'session.connection' => 'session',
                'session.store' => null,
                'session.lifetime' => 120,
                'session.cookie' => 'session',
                'session.encrypt' => false,
                'session.serialization' => 'php',
                'session.prefix' => $configuredPrefix,
            ]);
            $container->instance('cache', $cacheManager);

            $sessionStore = (new SessionManager($container))->driver();
            $handler = $this->handlerFromStore($sessionStore);

            $this->assertInstanceOf(CacheBasedSessionHandler::class, $handler);

            $sessionRedisStore = $handler->getCache()->getStore();

            $this->assertInstanceOf(RedisStore::class, $sessionRedisStore);
            $this->assertNotSame($sharedStore, $sessionRedisStore);
            $this->assertSame($expectedPrefix, $sessionRedisStore->getPrefix());
            $this->assertSame('session', $this->redisConnectionFromStore($sessionRedisStore));
            $this->assertSame('cache:', $sharedStore->getPrefix());
            $this->assertSame('cache', $this->redisConnectionFromStore($sharedStore));
        }
    }

    public function testRedisDriverRejectsNonRedisCacheStore(): void
    {
        $cacheManager = m::mock();
        $cacheManager->shouldReceive('store')
            ->once()
            ->with('array')
            ->andReturn(new CacheRepository(new ArrayStore));

        $container = $this->getContainer([
            'session.driver' => 'redis',
            'session.connection' => null,
            'session.store' => 'array',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
            'session.serialization' => 'php',
            'session.prefix' => 'application_session:',
        ]);
        $container->instance('cache', $cacheManager);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The [session.driver] value [redis] requires [session.store] to reference a Redis cache store.'
        );

        (new SessionManager($container))->driver();
    }

    public function testSessionSerializationUsesConfiguredStrategy(): void
    {
        foreach (['json', 'php'] as $serialization) {
            $manager = new SessionManager($this->getContainer([
                'session.driver' => 'array',
                'session.lifetime' => 120,
                'session.cookie' => 'session',
                'session.encrypt' => false,
                'session.serialization' => $serialization,
            ]));

            $this->assertSame($serialization, $this->serializationFromStore($manager->driver()));
        }
    }

    public function testEncryptedSessionSerializationUsesConfiguredStrategy(): void
    {
        $manager = new SessionManager($this->getContainer([
            'session.driver' => 'array',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => true,
            'session.serialization' => 'json',
        ]));

        $store = $manager->driver();

        $this->assertInstanceOf(EncryptedStore::class, $store);
        $this->assertSame('json', $this->serializationFromStore($store));
    }

    public function testBlockingConfigurationUsesDeclaredValues(): void
    {
        $manager = new SessionManager($this->getContainer([
            'session.driver' => 'array',
            'session.block' => true,
            'session.block_store' => 'locks',
            'session.block_lock_seconds' => 30,
            'session.block_wait_seconds' => 15,
        ]));

        $this->assertTrue($manager->shouldBlock());
        $this->assertSame('locks', $manager->blockDriver());
        $this->assertSame(30, $manager->defaultRouteBlockLockSeconds());
        $this->assertSame(15, $manager->defaultRouteBlockWaitSeconds());
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

    protected function redisConnectionFromStore(RedisStore $store): string
    {
        $property = new ReflectionProperty($store, 'connection');

        return $property->getValue($store);
    }

    protected function serializationFromStore(Store $store): string
    {
        $property = new ReflectionProperty($store, 'serialization');

        return $property->getValue($store);
    }
}

enum SessionIntegerIdentifier: int
{
    case Zero = 0;
}
