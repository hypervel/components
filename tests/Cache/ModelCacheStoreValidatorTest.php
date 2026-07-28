<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Cache\Exceptions\UnsupportedModelCacheStoreException;
use Hypervel\Cache\FailoverStore;
use Hypervel\Cache\FileStore;
use Hypervel\Cache\ModelCacheStoreValidator;
use Hypervel\Cache\NullStore;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\SessionStore;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\StorageStore;
use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Redis\RedisConfig;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis;

class ModelCacheStoreValidatorTest extends TestCase
{
    public function testAcceptsSupportedStoresAndSubclasses(): void
    {
        $validator = $this->validator();

        foreach ([
            m::mock(DatabaseStore::class),
            m::mock(FileStore::class),
            m::mock(StorageStore::class),
            m::mock(SwooleStore::class),
            $this->redisStore(),
        ] as $store) {
            $validator->validate($this->repository($store), 'Auth user cache');
            $this->assertTrue(true);
        }
    }

    public function testRejectsEveryUnsupportedStoreType(): void
    {
        $validator = $this->validator();

        foreach ([
            new ArrayStore,
            new WorkerArrayStore,
            new NullStore,
            m::mock(SessionStore::class),
            m::mock(FailoverStore::class),
        ] as $store) {
            try {
                $validator->validate($this->repository($store), 'Sanctum token cache');
                $this->fail('Expected the unsupported store to be rejected.');
            } catch (UnsupportedModelCacheStoreException $exception) {
                $this->assertStringContainsString('Sanctum token cache', $exception->getMessage());
                $this->assertStringContainsString($store::class, $exception->getMessage());
            }
        }
    }

    public function testAcceptsEveryLeafInANestedSupportedStack(): void
    {
        $stack = new StackStore([
            m::mock(FileStore::class),
            new StackStore([
                m::mock(StorageStore::class),
                $this->redisStore(),
            ]),
            m::mock(DatabaseStore::class),
        ]);

        $this->validator()->validate($this->repository($stack), 'Auth user cache');

        $this->assertTrue(true);
    }

    public function testReportsTheFullPathToANestedUnsupportedLayer(): void
    {
        $stack = new StackStore([
            m::mock(FileStore::class),
            new StackStore([
                m::mock(StorageStore::class),
                new ArrayStore,
            ]),
        ]);

        try {
            $this->validator()->validate($this->repository($stack), 'Auth user cache');
            $this->fail('Expected the nested array store to be rejected.');
        } catch (UnsupportedModelCacheStoreException $exception) {
            $this->assertStringContainsString('Auth user cache', $exception->getMessage());
            $this->assertStringContainsString(ArrayStore::class, $exception->getMessage());
            $this->assertStringContainsString('stack layer [1.1]', $exception->getMessage());
        }
    }

    public function testRejectsFailoverInsideANestedStack(): void
    {
        $failover = m::mock(FailoverStore::class);
        $stack = new StackStore([
            m::mock(FileStore::class),
            new StackStore([
                m::mock(StorageStore::class),
                $failover,
            ]),
        ]);

        try {
            $this->validator()->validate($this->repository($stack), 'Sanctum token cache');
            $this->fail('Expected the nested failover store to be rejected.');
        } catch (UnsupportedModelCacheStoreException $exception) {
            $this->assertStringContainsString($failover::class, $exception->getMessage());
            $this->assertStringContainsString('stack layer [1.1]', $exception->getMessage());
        }
    }

    public function testReportsTheLayerForARejectedRedisSerializerInsideAStack(): void
    {
        $stack = new StackStore([
            m::mock(FileStore::class),
            $this->redisStore(),
        ]);
        $validator = $this->validator(
            connectionOptions: ['serializer' => Redis::SERIALIZER_JSON],
        );

        try {
            $validator->validate($this->repository($stack), 'Auth user cache');
            $this->fail('Expected the nested Redis serializer to be rejected.');
        } catch (UnsupportedModelCacheStoreException $exception) {
            $this->assertStringContainsString('connection [cache]', $exception->getMessage());
            $this->assertStringContainsString('serializer [' . Redis::SERIALIZER_JSON . ']', $exception->getMessage());
            $this->assertStringContainsString('stack layer [1]', $exception->getMessage());
        }
    }

    public function testAcceptsAbsentAndDisabledRedisSerializersWithoutOpeningAConnection(): void
    {
        foreach ([
            [],
            ['serializer' => Redis::SERIALIZER_NONE],
            ['SeRiAlIzEr' => Redis::SERIALIZER_NONE, 'compression' => 1],
            [Redis::OPT_SERIALIZER => Redis::SERIALIZER_NONE],
        ] as $options) {
            $redis = m::mock(RedisFactory::class);
            $redis->shouldNotReceive('connection');
            $store = new RedisStore($redis, connection: 'cache');

            $this->validator(connectionOptions: $options)->validate(
                $this->repository($store),
                'Auth user cache',
            );

            $this->assertTrue(true);
        }
    }

    public function testAcceptsTheNativePhpSerializer(): void
    {
        $this->validator(
            connectionOptions: ['serializer' => Redis::SERIALIZER_PHP],
        )->validate($this->repository($this->redisStore()), 'Auth user cache');

        $this->assertTrue(true);
    }

    public function testAcceptsIgbinaryWhenTheRedisBuildSupportsIt(): void
    {
        if (! defined(Redis::class . '::SERIALIZER_IGBINARY')) {
            $this->markTestSkipped('The phpredis build does not support igbinary.');
        }

        $this->validator(
            connectionOptions: [
                'serializer' => (int) constant(Redis::class . '::SERIALIZER_IGBINARY'),
            ],
        )->validate($this->repository($this->redisStore()), 'Auth user cache');

        $this->assertTrue(true);
    }

    public function testAcceptsMsgpackOnlyInPhpOnlyMode(): void
    {
        if (! defined(Redis::class . '::SERIALIZER_MSGPACK')) {
            $this->markTestSkipped('The phpredis build does not support msgpack.');
        }

        $previous = ini_get('msgpack.php_only');

        if (ini_set('msgpack.php_only', '1') === false) {
            $this->markTestSkipped('msgpack.php_only cannot be changed in this process.');
        }

        try {
            $this->validator(
                connectionOptions: [
                    'serializer' => (int) constant(Redis::class . '::SERIALIZER_MSGPACK'),
                ],
            )->validate($this->repository($this->redisStore()), 'Sanctum token cache');

            $this->assertTrue(true);
        } finally {
            if (is_string($previous)) {
                ini_set('msgpack.php_only', $previous);
            }
        }
    }

    public function testRejectsMsgpackOutsidePhpOnlyMode(): void
    {
        if (! defined(Redis::class . '::SERIALIZER_MSGPACK')) {
            $this->markTestSkipped('The phpredis build does not support msgpack.');
        }

        $previous = ini_get('msgpack.php_only');

        if (ini_set('msgpack.php_only', '0') === false) {
            $this->markTestSkipped('msgpack.php_only cannot be changed in this process.');
        }

        try {
            $validator = $this->validator(connectionOptions: [
                'serializer' => (int) constant(Redis::class . '::SERIALIZER_MSGPACK'),
            ]);

            $this->expectException(UnsupportedModelCacheStoreException::class);
            $this->expectExceptionMessage('msgpack.php_only=1');

            $validator->validate($this->repository($this->redisStore()), 'Sanctum token cache');
        } finally {
            if (is_string($previous)) {
                ini_set('msgpack.php_only', $previous);
            }
        }
    }

    public function testRejectsJsonAndReportsTheConnectionAndSerializer(): void
    {
        $validator = $this->validator(
            connectionOptions: ['serializer' => Redis::SERIALIZER_JSON],
        );

        try {
            $validator->validate($this->repository($this->redisStore()), 'Auth user cache');
            $this->fail('Expected the JSON serializer to be rejected.');
        } catch (UnsupportedModelCacheStoreException $exception) {
            $this->assertStringContainsString('Auth user cache', $exception->getMessage());
            $this->assertStringContainsString('connection [cache]', $exception->getMessage());
            $this->assertStringContainsString('serializer [' . Redis::SERIALIZER_JSON . ']', $exception->getMessage());
            $this->assertStringContainsString('converts model objects to arrays', $exception->getMessage());
        }
    }

    public function testRejectsUnknownSerializerValues(): void
    {
        $validator = $this->validator(connectionOptions: ['serializer' => PHP_INT_MAX]);

        $this->expectException(UnsupportedModelCacheStoreException::class);
        $this->expectExceptionMessage('not verified to preserve model objects');

        $validator->validate($this->repository($this->redisStore()), 'Auth user cache');
    }

    public function testConnectionOptionsOverrideSharedOptions(): void
    {
        $this->validator(
            sharedOptions: ['serializer' => Redis::SERIALIZER_JSON],
            connectionOptions: ['serializer' => Redis::SERIALIZER_PHP],
        )->validate($this->repository($this->redisStore()), 'Auth user cache');

        $this->assertTrue(true);
    }

    public function testLastNumericRedisSerializerOptionWinsOverStringOption(): void
    {
        $this->validator(connectionOptions: [
            'serializer' => Redis::SERIALIZER_JSON,
            Redis::OPT_SERIALIZER => Redis::SERIALIZER_PHP,
        ])->validate($this->repository($this->redisStore()), 'Auth user cache');

        $this->assertTrue(true);
    }

    public function testLastStringRedisSerializerOptionWinsOverNumericOption(): void
    {
        $validator = $this->validator(connectionOptions: [
            Redis::OPT_SERIALIZER => Redis::SERIALIZER_PHP,
            'SERIALIZER' => Redis::SERIALIZER_JSON,
        ]);

        $this->expectException(UnsupportedModelCacheStoreException::class);
        $this->expectExceptionMessage('serializer [' . Redis::SERIALIZER_JSON . ']');

        $validator->validate($this->repository($this->redisStore()), 'Auth user cache');
    }

    /**
     * Create a validator with merged Redis options.
     *
     * @param array<array-key, mixed> $sharedOptions
     * @param array<array-key, mixed> $connectionOptions
     */
    private function validator(
        array $sharedOptions = [],
        array $connectionOptions = [],
    ): ModelCacheStoreValidator {
        return new ModelCacheStoreValidator(new RedisConfig(new ConfigRepository([
            'database' => [
                'redis' => [
                    'options' => $sharedOptions,
                    'cache' => [
                        'host' => '127.0.0.1',
                        'port' => 6379,
                        'options' => $connectionOptions,
                    ],
                ],
            ],
        ])));
    }

    /**
     * Create a Redis store without resolving a live connection.
     */
    private function redisStore(): RedisStore
    {
        $redis = m::mock(RedisFactory::class);
        $redis->shouldNotReceive('connection');

        return new RedisStore($redis, connection: 'cache');
    }

    /**
     * Wrap a store in a cache repository.
     */
    private function repository(Store $store): CacheRepository
    {
        return new Repository($store);
    }
}
