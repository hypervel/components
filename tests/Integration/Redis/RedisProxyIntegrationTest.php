<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\RedisFactory;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use Redis as PhpRedis;

/**
 * @group integration
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class RedisProxyIntegrationTest extends TestCase
{
    use InteractsWithRedis;
    use RunTestsInCoroutine;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->get(ConfigInterface::class);
        $this->configureRedisForTesting($config);
    }

    public function testRedisOptionPrefix(): void
    {
        $prefixedName = $this->createRedisConnectionWithPrefix('test:');
        $plainName = $this->createRedisConnectionWithPrefix('');

        $prefixed = Redis::connection($prefixedName);
        $plain = Redis::connection($plainName);

        $prefixed->flushdb();
        $prefixed->set('test', 'yyy');

        $this->assertSame('yyy', $prefixed->get('test'));
        $this->assertSame('yyy', $plain->get('test:test'));
    }

    public function testRedisOptionSerializer(): void
    {
        $serializedName = $this->createRedisConnectionWithOptions(
            name: 'test_serializer',
            options: [
                'prefix' => '',
                'serializer' => PhpRedis::SERIALIZER_PHP,
            ],
        );
        $plainName = $this->createRedisConnectionWithOptions(
            name: 'test_plain',
            options: ['prefix' => ''],
        );

        $serialized = Redis::connection($serializedName);
        $plain = Redis::connection($plainName);

        $serialized->flushdb();
        $serialized->set('test', 'yyy');

        $this->assertSame('yyy', $serialized->get('test'));
        $this->assertSame('s:3:"yyy";', $plain->get('test'));
    }

    public function testHyperLogLog(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $result = $redis->pfAdd('test:hyperloglog', ['123', 'fff']);
        $this->assertSame(1, $result);
        $result = $redis->pfAdd('test:hyperloglog', ['123']);
        $this->assertSame(0, $result);

        $this->assertSame(2, $redis->pfCount('test:hyperloglog'));
        $redis->pfAdd('test:hyperloglog2', [1234]);
        $redis->pfMerge('test:hyperloglog2', ['test:hyperloglog']);
        $this->assertSame(3, $redis->pfCount('test:hyperloglog2'));
        $this->assertFalse($redis->pfAdd('test:hyperloglog3', []));
    }

    public function testScanReturnsCursorAndKeysTuple(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $expected = ['scan:1', 'scan:2', 'scan:3', 'scan:4'];
        foreach ($expected as $value) {
            $redis->set($value, '1');
        }

        $cursor = null;
        $collected = [];
        while (($chunk = $redis->scan($cursor, 'scan:*', 2)) !== false) {
            [$cursor, $keys] = $chunk;
            $collected = array_merge($collected, $keys);
        }

        $collected = array_values(array_unique($collected));
        sort($collected);

        $this->assertSame($expected, $collected);
        $this->assertSame(0, $cursor);
    }

    public function testHscanReturnsCursorAndFieldMapTuple(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $expected = ['scan:1', 'scan:2', 'scan:3', 'scan:4'];
        foreach ($expected as $value) {
            $redis->hSet('scaner', $value, '1');
        }

        $cursor = null;
        $fields = [];
        while (($chunk = $redis->hscan('scaner', $cursor, 'scan:*', 2)) !== false) {
            [$cursor, $map] = $chunk;
            $fields = array_merge($fields, array_keys($map));
        }

        $fields = array_values(array_unique($fields));
        sort($fields);

        $this->assertSame($expected, $fields);
        $this->assertSame(0, $cursor);
    }

    public function testPipelineCallbackRunsCommands(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $key = 'pipeline:' . uniqid();

        $results = $redis->pipeline(function (PhpRedis $pipeline) use ($key) {
            $pipeline->incr($key);
            $pipeline->incr($key);
            $pipeline->incr($key);
        });

        $this->assertSame([1, 2, 3], $results);
        $this->assertSame('3', $redis->get($key));
    }

    public function testTransactionCallbackRunsCommands(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $key = 'transaction:' . uniqid();

        $results = $redis->transaction(function (PhpRedis $transaction) use ($key) {
            $transaction->incr($key);
            $transaction->incr($key);
            $transaction->incr($key);
        });

        $this->assertSame([1, 2, 3], $results);
        $this->assertSame('3', $redis->get($key));
    }

    /**
     * Create a Redis connection with custom options for integration assertions.
     *
     * @param array<string, mixed> $options
     */
    private function createRedisConnectionWithOptions(string $name, array $options): string
    {
        $config = $this->app->get(ConfigInterface::class);

        if ($config->get("database.redis.{$name}") !== null) {
            return $name;
        }

        $config->set("database.redis.{$name}", [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'auth' => env('REDIS_AUTH', null) ?: null,
            'port' => (int) env('REDIS_PORT', 6379),
            'db' => (int) env('REDIS_DB', $this->redisTestDatabase),
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
            'options' => $options,
        ]);

        // RedisFactory snapshots pools at construction, so reset after adding runtime test connections.
        $this->app->forgetInstance(RedisFactory::class);

        return $name;
    }
}
