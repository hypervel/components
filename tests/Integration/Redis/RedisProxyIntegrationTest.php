<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisFactory;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use Redis as PhpRedis;
use Throwable;

use function Hypervel\Coroutine\go;

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
        $config = $app->make('config');
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

    public function testZSetAddAnd(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $key = 'test:zset:add:remove';

        $redis->zAdd($key, microtime(true) * 1000 + 100, 'test');
        usleep(1_000);

        $result = $redis->zRangeByScore($key, '0', (string) (microtime(true) * 1000));
        $this->assertEmpty($result);
    }

    public function testPipelineReturnsNativeRedisInstanceAndExecutesCallback(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $pipeline = $redis->pipeline();
        $this->assertInstanceOf(PhpRedis::class, $pipeline);

        $key = 'pipeline:' . uniqid();
        $results = $redis->pipeline(function (PhpRedis $pipe) use ($key) {
            $pipe->incr($key);
            $pipe->incr($key);
            $pipe->incr($key);
        });

        $this->assertSame([1, 2, 3], $results);
        $this->assertSame('3', $redis->get($key));
    }

    public function testTransactionReturnsNativeRedisInstanceAndExecutesCallback(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $transaction = $redis->transaction();
        $this->assertInstanceOf(PhpRedis::class, $transaction);

        $key = 'transaction:' . uniqid();
        $results = $redis->transaction(function (PhpRedis $tx) use ($key) {
            $tx->incr($key);
            $tx->incr($key);
            $tx->incr($key);
        });

        $this->assertSame([1, 2, 3], $results);
        $this->assertSame('3', $redis->get($key));
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
    }

    public function testSscanReturnsCursorAndMembersTuple(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $expected = ['member:1', 'member:2', 'member:3', 'member:4'];
        foreach ($expected as $member) {
            $redis->sAdd('scanset', $member);
        }

        $cursor = null;
        $collected = [];
        while (($chunk = $redis->sscan('scanset', $cursor, 'member:*', 2)) !== false) {
            [$cursor, $members] = $chunk;
            $collected = array_merge($collected, $members);
        }

        $collected = array_values(array_unique($collected));
        sort($collected);

        $this->assertSame($expected, $collected);
    }

    public function testZscanReturnsCursorAndScoreMapTuple(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $members = ['zmem:1' => 1.0, 'zmem:2' => 2.0, 'zmem:3' => 3.0, 'zmem:4' => 4.0];
        foreach ($members as $member => $score) {
            $redis->zadd('scanzset', $score, $member);
        }

        $cursor = null;
        $collected = [];
        while (($chunk = $redis->zscan('scanzset', $cursor, 'zmem:*', 2)) !== false) {
            [$cursor, $map] = $chunk;
            foreach ($map as $member => $score) {
                $collected[$member] = $score;
            }
        }

        ksort($collected);

        $this->assertSame($members, $collected);
    }

    public function testRedisPipelineConcurrentExecs(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->rPush('pipeline:list', 'A');
        $redis->rPush('pipeline:list', 'B');
        $redis->rPush('pipeline:list', 'C');
        $redis->rPush('pipeline:list', 'D');
        $redis->rPush('pipeline:list', 'E');

        $first = new Channel(1);
        $second = new Channel(1);

        go(static function () use ($redis, $first) {
            $redis->pipeline();
            usleep(2_000);
            $redis->lRange('pipeline:list', 0, 1);
            $redis->lTrim('pipeline:list', 2, -1);
            usleep(1_000);
            $first->push($redis->exec());
        });

        go(static function () use ($redis, $second) {
            $redis->pipeline();
            usleep(1_000);
            $redis->lRange('pipeline:list', 0, 1);
            $redis->lTrim('pipeline:list', 2, -1);
            usleep(20_000);
            $second->push($redis->exec());
        });

        $this->assertSame([['A', 'B'], true], $first->pop());
        $this->assertSame([['C', 'D'], true], $second->pop());
    }

    public function testPipelineCallbackAndSelect(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->select(1);
        $redis->set('concurrent_pipeline_test_callback_and_select_value', $id = uniqid(), 'EX', 600);

        $key = 'concurrent_pipeline_test_callback_and_select';
        $results = $redis->pipeline(function (PhpRedis $pipe) use ($key) {
            $pipe->set($key, "value_{$key}");
            $pipe->incr("{$key}_counter");
            $pipe->get($key);
            $pipe->get("{$key}_counter");
        });

        $this->assertCount(4, $results);
        $this->assertSame($id, $redis->get('concurrent_pipeline_test_callback_and_select_value'));
    }

    public function testPipelineCallbackAndPipeline(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $openPipeline = $redis->pipeline();
        // This uses integer expiry while a pipeline is open to assert queue-mode bypasses transformed callSet().
        $redis->set('concurrent_pipeline_test_callback_and_select_value', $id = uniqid(), 600);

        $key = 'concurrent_pipeline_test_callback_and_select';
        $callbackResults = $redis->pipeline(function (PhpRedis $pipe) use ($key) {
            $pipe->set($key, "value_{$key}");
            $pipe->incr("{$key}_counter");
            $pipe->get($key);
            $pipe->get("{$key}_counter");
        });

        go(static function () use ($redis) {
            $redis->select(1);
            $redis->set('xxx', 'x');
            $redis->set('xxx', 'x');
            $redis->set('xxx', 'x');
        });

        $openPipeline->set('xxxxxx', 'x');
        $openPipeline->set('xxxxxx', 'x');
        $openPipeline->set('xxxxxx', 'x');
        $openPipeline->set('xxxxxx', 'x');

        $this->assertInstanceOf(PhpRedis::class, $openPipeline);
        // The pre-callback set() is queued on the open pipeline connection, so callback exec includes 5 queued results.
        $this->assertCount(5, $callbackResults);
        $this->assertSame($id, $redis->get('concurrent_pipeline_test_callback_and_select_value'));
    }

    public function testSelectIsolationAcrossCoroutines(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $uniqueKey = 'select_isolation_' . uniqid();

        $channelA = new Channel(1);
        $channelB = new Channel(1);

        // Coroutine A: select db 1, set a key
        go(static function () use ($redis, $uniqueKey, $channelA) {
            $redis->select(1);
            $redis->set($uniqueKey, 'from_db1');
            $channelA->push($redis->get($uniqueKey));
        });

        // Coroutine B: stays on default db 0, should NOT see the key
        go(static function () use ($redis, $uniqueKey, $channelB) {
            // Small delay to let coroutine A execute first
            usleep(5_000);
            $channelB->push($redis->get($uniqueKey));
        });

        // Coroutine A should see its key on db 1
        $this->assertSame('from_db1', $channelA->pop());

        // Coroutine B should NOT see the key (it's on db 0)
        $this->assertNull($channelB->pop());

        // Clean up db 1
        $redis->select(1);
        $redis->del($uniqueKey);
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

    public function testWithConnectionTransformFalseSupportsPipelineCallbacks(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $key = 'pipeline:transform_off:' . uniqid();
        $results = $redis->withConnection(function (RedisConnection $connection) use ($key) {
            $connection->pipeline();
            $connection->set($key, 'value', 600);
            $connection->get($key);

            return $connection->exec();
        }, transform: false);

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertSame('value', $redis->get($key));
    }

    public function testWithConnectionTransformFalseSupportsTransactionCallbacks(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $key = 'transaction:transform_off:' . uniqid();
        $results = $redis->withConnection(function (RedisConnection $connection) use ($key) {
            $connection->multi();
            $connection->set($key, '0', 600);
            $connection->incr($key);

            return $connection->exec();
        }, transform: false);

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertSame('1', $redis->get($key));
    }

    public function testConcurrentPipelineCallbacksWithLimitedConnectionPool(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            name: 'test_concurrent_pipeline_callbacks',
            options: ['prefix' => ''],
            maxConnections: 3,
        ));
        $redis->flushdb();

        $concurrentOperations = 20;
        $channels = [];

        for ($i = 0; $i < $concurrentOperations; ++$i) {
            $channels[$i] = new Channel(1);
        }

        for ($i = 0; $i < $concurrentOperations; ++$i) {
            go(function () use ($redis, $channels, $i) {
                try {
                    $key = "concurrent_pipeline_test_{$i}";

                    $results = $redis->pipeline(function (PhpRedis $pipe) use ($key) {
                        $pipe->set($key, "value_{$key}");
                        $pipe->incr("{$key}_counter");
                        $pipe->get($key);
                        $pipe->get("{$key}_counter");
                    });

                    sleep(1);

                    $this->assertCount(4, $results);
                    $this->assertTrue($results[0]);
                    $this->assertSame(1, $results[1]);
                    $this->assertSame("value_{$key}", $results[2]);
                    $this->assertSame('1', $results[3]);

                    $channels[$i]->push(['success' => true, 'operation' => 'pipeline']);
                } catch (Throwable $exception) {
                    $channels[$i]->push(['success' => false, 'error' => $exception->getMessage()]);
                }
            });
        }

        $successCount = 0;
        for ($i = 0; $i < $concurrentOperations; ++$i) {
            $result = $channels[$i]->pop(10.0);
            $this->assertNotFalse($result, "Operation {$i} timed out - possible connection pool exhaustion");

            if ($result['success']) {
                ++$successCount;
            } else {
                $this->fail("Concurrent operation {$i} failed: " . $result['error']);
            }
        }

        $this->assertSame(
            $concurrentOperations,
            $successCount,
            "All {$concurrentOperations} concurrent pipeline operations should succeed with only 3 max connections",
        );

        for ($i = 0; $i < $concurrentOperations; ++$i) {
            $redis->del("concurrent_pipeline_test_{$i}");
            $redis->del("concurrent_pipeline_test_{$i}_counter");
        }
    }

    public function testConcurrentTransactionCallbacksWithLimitedConnectionPool(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            name: 'test_concurrent_transaction_callbacks',
            options: ['prefix' => ''],
            maxConnections: 3,
        ));
        $redis->flushdb();

        $concurrentOperations = 20;
        $channels = [];

        for ($i = 0; $i < $concurrentOperations; ++$i) {
            $channels[$i] = new Channel(1);
        }

        for ($i = 0; $i < $concurrentOperations; ++$i) {
            go(function () use ($redis, $channels, $i) {
                try {
                    $key = "concurrent_transaction_test_{$i}";

                    $results = $redis->transaction(function (PhpRedis $transaction) use ($key) {
                        $transaction->set($key, "tx_value_{$key}");
                        $transaction->incr("{$key}_counter");
                        $transaction->get($key);
                    });

                    sleep(1);

                    $this->assertCount(3, $results);
                    $this->assertTrue($results[0]);
                    $this->assertSame(1, $results[1]);
                    $this->assertSame("tx_value_{$key}", $results[2]);

                    $channels[$i]->push(['success' => true, 'operation' => 'transaction']);
                } catch (Throwable $exception) {
                    $channels[$i]->push(['success' => false, 'error' => $exception->getMessage()]);
                }
            });
        }

        $successCount = 0;
        for ($i = 0; $i < $concurrentOperations; ++$i) {
            $result = $channels[$i]->pop(10.0);
            $this->assertNotFalse($result, "Transaction operation {$i} timed out - possible connection pool exhaustion");

            if ($result['success']) {
                ++$successCount;
            } else {
                $this->fail("Concurrent transaction {$i} failed: " . $result['error']);
            }
        }

        $this->assertSame(
            $concurrentOperations,
            $successCount,
            "All {$concurrentOperations} concurrent transaction operations should succeed with only 3 max connections",
        );

        for ($i = 0; $i < $concurrentOperations; ++$i) {
            $redis->del("concurrent_transaction_test_{$i}");
            $redis->del("concurrent_transaction_test_{$i}_counter");
        }
    }

    /**
     * Create a Redis connection with custom options for integration assertions.
     *
     * @param array<string, mixed> $options
     */
    private function createRedisConnectionWithOptions(string $name, array $options, int $maxConnections = 10): string
    {
        $config = $this->app->make('config');

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
                'max_connections' => $maxConnections,
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
