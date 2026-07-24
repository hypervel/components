<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use Redis as PhpRedis;
use Throwable;

use function Hypervel\Coroutine\go;

class RedisProxyIntegrationTest extends TestCase
{
    use InteractsWithRedis;

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

        $redis->select($this->getSecondaryRedisDb());
        $valueKey = 'pipeline_select_value_' . uniqid();
        $redis->set($valueKey, $id = uniqid(), 'EX', 600);

        try {
            $key = 'pipeline_select_' . uniqid();
            $results = $redis->pipeline(function (PhpRedis $pipe) use ($key) {
                $pipe->set($key, "value_{$key}");
                $pipe->incr("{$key}_counter");
                $pipe->get($key);
                $pipe->get("{$key}_counter");
            });

            $this->assertCount(4, $results);
            $this->assertSame($id, $redis->get($valueKey));
        } finally {
            $redis->select($this->getSecondaryRedisDb());
            $redis->del($valueKey);
            $redis->select($this->getParallelRedisDb());
        }
    }

    public function testPipelineCallbackAndPipeline(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $openPipeline = $redis->pipeline();
        // This uses integer expiry while a pipeline is open to assert queue-mode bypasses transformed callSet().
        $valueKey = 'pipeline_pipeline_value_' . uniqid();
        $redis->set($valueKey, $id = uniqid(), 600);

        $key = 'pipeline_pipeline_' . uniqid();
        $callbackResults = $redis->pipeline(function (PhpRedis $pipe) use ($key) {
            $pipe->set($key, "value_{$key}");
            $pipe->incr("{$key}_counter");
            $pipe->get($key);
            $pipe->get("{$key}_counter");
        });

        $secondaryDb = $this->getSecondaryRedisDb();
        $selectKey = 'pipeline_select_junk_' . uniqid();
        $secondaryWriteFinished = new Channel(1);
        go(static function () use ($redis, $secondaryDb, $selectKey, $secondaryWriteFinished) {
            $redis->select($secondaryDb);
            $redis->set($selectKey, 'x');
            $redis->set($selectKey, 'x');
            $redis->set($selectKey, 'x');
            $secondaryWriteFinished->push(true);
        });

        $pipelineKey = 'pipeline_junk_' . uniqid();
        $openPipeline->set($pipelineKey, 'x');
        $openPipeline->set($pipelineKey, 'x');
        $openPipeline->set($pipelineKey, 'x');
        $openPipeline->set($pipelineKey, 'x');

        try {
            $this->assertTrue($secondaryWriteFinished->pop());
            $this->assertInstanceOf(PhpRedis::class, $openPipeline);
            // The pre-callback set() is queued on the open pipeline connection, so callback exec includes 5 queued results.
            $this->assertCount(5, $callbackResults);
            $this->assertSame($id, $redis->get($valueKey));
        } finally {
            $redis->select($secondaryDb);
            $redis->del($selectKey);
            $redis->select($this->getParallelRedisDb());
        }
    }

    public function testOpenPipelineReshapesWrapperSetArguments(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $key = 'pipeline_wrapper_set_' . uniqid();
        $pipeline = $redis->pipeline();

        $redis->set($key, 'value', 'EX', 600, 'NX');

        $this->assertSame([true], $pipeline->exec());
        $this->assertSame('value', $redis->get($key));
    }

    public function testOpenPipelineReshapesWrapperEvalArguments(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $key = 'pipeline_wrapper_eval_' . uniqid();
        $redis->set($key, 'value');

        $pipeline = $redis->pipeline();

        $redis->eval('return redis.call("GET", KEYS[1])', 1, $key);

        $this->assertSame(['value'], $pipeline->exec());
    }

    public function testOpenPipelineReshapesWrapperEvalshaArguments(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $key = 'pipeline_wrapper_evalsha_' . uniqid();
        $redis->set($key, 'value');

        $pipeline = $redis->pipeline();

        $redis->evalsha('return redis.call("GET", KEYS[1])', 1, $key);

        $this->assertSame(['value'], $pipeline->exec());
    }

    public function testSelectIsolationAcrossCoroutines(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $uniqueKey = 'select_isolation_' . uniqid();
        $secondaryDb = $this->getSecondaryRedisDb();

        $channelA = new Channel(1);
        $channelB = new Channel(1);

        // Coroutine A: select secondary db, set a key
        go(static function () use ($redis, $uniqueKey, $channelA, $secondaryDb) {
            $redis->select($secondaryDb);
            $redis->set($uniqueKey, 'from_secondary_db');
            $channelA->push($redis->get($uniqueKey));
        });

        // Coroutine B: stays on primary db, should NOT see the key
        go(static function () use ($redis, $uniqueKey, $channelB) {
            // Small delay to let coroutine A execute first
            usleep(5_000);
            $channelB->push($redis->get($uniqueKey));
        });

        // Coroutine A should see its key on secondary db
        $this->assertSame('from_secondary_db', $channelA->pop());

        // Coroutine B should NOT see the key (it's on primary db)
        $this->assertNull($channelB->pop());

        // Clean up secondary db
        $redis->select($secondaryDb);
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

    public function testAbandonedMultiDiscardsTheNativeGeneration(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            'test_abandoned_multi',
            ['prefix' => ''],
        ));
        $redis->flushdb();

        $abandoned = $redis->multi();
        $redis->releaseContextConnection();
        $replacement = $this->nativeClient($redis);

        $this->assertNotSame($abandoned, $replacement);
        $this->assertSame(PhpRedis::ATOMIC, $replacement->getMode());
        $this->assertTrue($redis->set('after:multi', 'healthy'));
        $this->assertSame('healthy', $redis->get('after:multi'));
    }

    public function testAbandonedPipelineDiscardsTheNativeGeneration(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            'test_abandoned_pipeline',
            ['prefix' => ''],
        ));
        $redis->flushdb();

        $abandoned = $redis->pipeline();
        $redis->releaseContextConnection();
        $replacement = $this->nativeClient($redis);

        $this->assertNotSame($abandoned, $replacement);
        $this->assertSame(PhpRedis::ATOMIC, $replacement->getMode());
        $this->assertTrue($redis->set('after:pipeline', 'healthy'));
        $this->assertSame('healthy', $redis->get('after:pipeline'));
    }

    public function testAbandonedWatchDiscardsTheNativeGeneration(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            'test_abandoned_watch',
            ['prefix' => ''],
        ));
        $redis->flushdb();

        $this->assertTrue($redis->watch('watched:key'));
        $abandoned = $this->nativeClient($redis);
        $redis->releaseContextConnection();
        $replacement = $this->nativeClient($redis);

        $this->assertNotSame($abandoned, $replacement);
        $this->assertSame(PhpRedis::ATOMIC, $replacement->getMode());
        $this->assertTrue($redis->set('after:watch', 'healthy'));
        $this->assertSame('healthy', $redis->get('after:watch'));
    }

    public function testWatchAndCallbackTransactionUseOneHealthyNativeClient(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            'test_watch_transaction',
            ['prefix' => ''],
        ));
        $redis->flushdb();
        $key = 'watch:transaction';
        $redis->set($key, 'before');

        $this->assertTrue($redis->watch($key));
        $watched = $this->nativeClient($redis);
        $result = $redis->transaction(function (PhpRedis $transaction) use ($key, $watched): void {
            $this->assertSame($watched, $transaction);
            $transaction->get($key);
            $transaction->set($key, 'after');
        });

        $this->assertSame(['before', true], $result);

        $redis->releaseContextConnection();

        $this->assertSame($watched, $this->nativeClient($redis));
        $this->assertSame('after', $redis->get($key));
    }

    public function testWatchConflictClearsConsumedStateAndReusesNativeClient(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            'test_watch_conflict',
            ['prefix' => ''],
        ));
        $other = Redis::connection($this->createRedisConnectionWithOptions(
            'test_watch_conflict_other',
            ['prefix' => ''],
        ));
        $redis->flushdb();
        $key = 'watch:conflict';
        $redis->set($key, 'before');

        $this->assertTrue($redis->watch($key));
        $watched = $this->nativeClient($redis);
        $other->set($key, 'changed');
        $result = $redis->transaction(static function (PhpRedis $transaction) use ($key): void {
            $transaction->set($key, 'after');
        });

        $this->assertFalse($result);

        $redis->releaseContextConnection();

        $this->assertSame($watched, $this->nativeClient($redis));
        $this->assertSame('changed', $redis->get($key));
    }

    public function testCallbackPipelineDoesNotConsumeWatchState(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            'test_watch_pipeline',
            ['prefix' => ''],
        ));
        $redis->flushdb();
        $redis->set('watch:pipeline', 'value');

        $this->assertTrue($redis->watch('watch:pipeline'));
        $watched = $this->nativeClient($redis);
        $this->assertSame(
            ['value'],
            $redis->pipeline(static function (PhpRedis $pipeline): void {
                $pipeline->get('watch:pipeline');
            })
        );

        $redis->releaseContextConnection();

        $this->assertNotSame($watched, $this->nativeClient($redis));
    }

    public function testNativeDiscardKeepsTheHealthyWrapperReusable(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            'test_native_discard',
            ['prefix' => ''],
        ));
        $redis->flushdb();
        $key = 'discard:key';

        $this->assertTrue($redis->watch($key));
        $native = $this->nativeClient($redis);
        $redis->multi();
        $redis->set($key, 'discarded');

        $this->assertTrue($redis->discard());

        $redis->releaseContextConnection();

        $this->assertSame($native, $this->nativeClient($redis));
        $this->assertNull($redis->get($key));
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
     * Get the exact native client held by a Redis proxy.
     */
    private function nativeClient(RedisProxy $redis): PhpRedis
    {
        return $redis->withConnection(function (RedisConnection $connection): PhpRedis {
            $client = $connection->client();
            $this->assertInstanceOf(PhpRedis::class, $client);

            return $client;
        });
    }
}
