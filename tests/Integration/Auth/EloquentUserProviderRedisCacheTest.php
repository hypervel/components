<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Redis as PhpRedis;

#[WithMigration]
class EloquentUserProviderRedisCacheTest extends TestCase
{
    use InteractsWithRedis;
    use RefreshDatabase;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $database = $this->getParallelRedisDb();
        $connection = $config->array('database.redis.default');
        $connections = [
            'auth-none' => $this->redisConnection($connection, $database, PhpRedis::SERIALIZER_NONE),
            'auth-php' => $this->redisConnection($connection, $database, PhpRedis::SERIALIZER_PHP),
        ];
        $stores = [
            'auth-redis-none' => $this->redisStore('auth-none', 'auth:none:'),
            'auth-redis-php' => $this->redisStore('auth-php', 'auth:php:'),
        ];

        if (defined('Redis::SERIALIZER_IGBINARY')) {
            $connections['auth-igbinary'] = $this->redisConnection(
                $connection,
                $database,
                (int) constant('Redis::SERIALIZER_IGBINARY'),
            );
            $stores['auth-redis-igbinary'] = $this->redisStore(
                'auth-igbinary',
                'auth:igbinary:',
            );
        }

        if (defined('Redis::SERIALIZER_MSGPACK')) {
            $connections['auth-msgpack'] = $this->redisConnection(
                $connection,
                $database,
                (int) constant('Redis::SERIALIZER_MSGPACK'),
            );
            $stores['auth-redis-msgpack'] = $this->redisStore(
                'auth-msgpack',
                'auth:msgpack:',
            );
        }

        $config->set([
            'database.redis' => array_replace(
                $config->array('database.redis'),
                $connections,
            ),
            'cache.serializable_classes' => false,
            'cache.stores' => array_replace(
                $config->array('cache.stores'),
                $stores,
            ),
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => User::class,
                'cache' => [
                    'enabled' => true,
                    'store' => 'auth-redis-none',
                ],
            ],
        ]);
    }

    protected function afterRefreshingDatabase(): void
    {
        User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('secret'),
        ]);
    }

    public function testSerializerNoneEnforcesThePolicyAndPreservesTheModel(): void
    {
        $this->assertModelRoundTrip('auth-redis-none');
    }

    public function testNativePhpSerializerPreservesTheModel(): void
    {
        $this->assertModelRoundTrip('auth-redis-php');
    }

    public function testIgbinarySerializerPreservesTheModelWhenAvailable(): void
    {
        if (! defined('Redis::SERIALIZER_IGBINARY')) {
            $this->markTestSkipped('The installed phpredis build does not support igbinary.');
        }

        $this->assertModelRoundTrip('auth-redis-igbinary');
    }

    public function testPhpOnlyMsgpackSerializerPreservesTheModelWhenAvailable(): void
    {
        if (! defined('Redis::SERIALIZER_MSGPACK')) {
            $this->markTestSkipped('The installed phpredis build does not support msgpack.');
        }

        if (filter_var(ini_get('msgpack.php_only'), FILTER_VALIDATE_BOOL) !== true) {
            $this->markTestSkipped('msgpack.php_only=1 is required for model caching.');
        }

        $this->assertModelRoundTrip('auth-redis-msgpack');
    }

    /**
     * Assert a provider returns its concrete model without a second database query.
     */
    protected function assertModelRoundTrip(string $store): void
    {
        $user = User::query()->firstOrFail();
        $provider = new EloquentUserProvider($this->app->make('hash'), User::class);
        $provider->enableCache($store);

        DB::enableQueryLog();

        $first = $provider->retrieveById($user->getAuthIdentifier());

        $this->assertInstanceOf(User::class, $first);
        $this->assertSame(1, $this->countQueriesForTable('users'));

        DB::flushQueryLog();

        $second = $provider->retrieveById($user->getAuthIdentifier());

        $this->assertInstanceOf(User::class, $second);
        $this->assertSame(0, $this->countQueriesForTable('users'));
    }

    /**
     * Build a Redis connection using the assigned parallel-test database.
     *
     * @param array<string, mixed> $connection
     * @return array<string, mixed>
     */
    protected function redisConnection(
        array $connection,
        int $database,
        int $serializer,
    ): array {
        return array_replace($connection, [
            'database' => $database,
            'options' => [
                'serializer' => $serializer,
            ],
        ]);
    }

    /**
     * Build a Redis cache store configuration.
     *
     * @return array<string, mixed>
     */
    protected function redisStore(string $connection, string $prefix): array
    {
        return [
            'driver' => 'redis',
            'connection' => $connection,
            'prefix' => $prefix,
        ];
    }

    /**
     * Count logged select queries for a table.
     */
    protected function countQueriesForTable(string $table): int
    {
        return count(array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_starts_with(strtolower($query['query'] ?? ''), 'select')
                && str_contains($query['query'] ?? '', $table)
        ));
    }
}
