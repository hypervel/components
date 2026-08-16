<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use Redis as PhpRedis;
use RedisCluster;
use RedisClusterException;

class RedisClusterIntegrationTest extends TestCase
{
    use InteractsWithRedis;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->usingRedisCluster()) {
            $this->markTestSkipped('These behaviors are specific to Redis Cluster.');
        }
    }

    public function testSameSlotCallbackTransactionKeepsPoolReusable(): void
    {
        $redis = $this->maxOneRedisConnection('test_cluster_same_slot_transaction');
        $firstKey = '{cluster-transaction}:first';
        $secondKey = '{cluster-transaction}:second';
        $native = $this->nativeClient($redis);

        $result = $redis->transaction(static function (RedisCluster $transaction) use ($firstKey, $secondKey): void {
            $transaction->set($firstKey, 'first');
            $transaction->set($secondKey, 'second');
        });

        $this->assertSame([true, true], $result);
        $this->assertSame($native, $this->nativeClient($redis));
        $this->assertSame('first', $redis->get($firstKey));
        $this->assertSame('second', $redis->get($secondKey));
    }

    public function testCrossSlotTransactionFailureReconnectsBeforePoolReuse(): void
    {
        $redis = $this->maxOneRedisConnection('test_cluster_failed_transaction');
        [$firstKey, $secondKey] = $this->sameMasterDifferentSlotKeys($this->nativeClient($redis));
        $redis->set($firstKey, 'first-before');
        $redis->set($secondKey, 'second-before');
        $failedClient = $this->nativeClient($redis);

        try {
            $redis->transaction(static function (RedisCluster $transaction) use ($firstKey, $secondKey): void {
                $transaction->set($firstKey, 'first-after');
                $transaction->set($secondKey, 'second-after');
            });
            $this->fail('Expected the cross-slot transaction to fail.');
        } catch (RedisClusterException $exception) {
            $this->assertStringContainsString('Error processing EXEC across the cluster', $exception->getMessage());
        }

        $replacementClient = $this->nativeClient($redis);

        $this->assertNotSame($failedClient, $replacementClient);
        $this->assertSame('first-before', $redis->get($firstKey));
        $this->assertSame('second-before', $redis->get($secondKey));
    }

    public function testPinnedConnectionRecoversAfterCaughtCrossSlotTransactionFailure(): void
    {
        $redis = $this->maxOneRedisConnection('test_cluster_pinned_failed_transaction');
        [$firstKey, $secondKey] = $this->sameMasterDifferentSlotKeys($this->nativeClient($redis));
        $redis->set($firstKey, 'first-before');
        $redis->set($secondKey, 'second-before');
        $replacementClient = null;

        $redis->withPinnedConnection(function () use ($redis, $firstKey, $secondKey, &$replacementClient): void {
            $failedClient = $this->nativeClient($redis);

            try {
                $redis->transaction(static function (RedisCluster $transaction) use ($firstKey, $secondKey): void {
                    $transaction->set($firstKey, 'first-after');
                    $transaction->set($secondKey, 'second-after');
                });
                $this->fail('Expected the cross-slot transaction to fail.');
            } catch (RedisClusterException $exception) {
                $this->assertStringContainsString('Error processing EXEC across the cluster', $exception->getMessage());
            }

            $replacementClient = $this->nativeClient($redis);

            $this->assertNotSame($failedClient, $replacementClient);
            $this->assertSame('first-before', $redis->get($firstKey));
            $this->assertSame('second-before', $redis->get($secondKey));
        });

        $this->assertSame($replacementClient, $this->nativeClient($redis));
    }

    public function testAbandonedMultiDiscardsNativeClient(): void
    {
        $redis = $this->maxOneRedisConnection('test_cluster_abandoned_multi');
        $abandoned = $redis->multi();

        $redis->releaseContextConnection();

        $replacement = $this->nativeClient($redis);

        $this->assertNotSame($abandoned, $replacement);
        $this->assertSame(PhpRedis::ATOMIC, $replacement->getMode());
        $this->assertTrue($redis->set('{cluster-after-multi}:key', 'healthy'));
        $this->assertSame('healthy', $redis->get('{cluster-after-multi}:key'));
    }

    public function testWatchAndSameSlotTransactionSucceedOnOneNativeClient(): void
    {
        $redis = $this->maxOneRedisConnection('test_cluster_watch_transaction');
        $key = '{cluster-watch}:key';
        $redis->set($key, 'before');

        $this->assertTrue($redis->watch($key));
        $watchedClient = $this->nativeClient($redis);
        $result = $redis->transaction(function (RedisCluster $transaction) use ($key, $watchedClient): void {
            $this->assertSame($watchedClient, $transaction);
            $transaction->get($key);
            $transaction->set($key, 'after');
        });

        $this->assertSame(['before', true], $result);

        $redis->releaseContextConnection();

        $this->assertSame($watchedClient, $this->nativeClient($redis));
        $this->assertSame('after', $redis->get($key));
    }

    public function testWatchConflictPinsCurrentPhpRedisClusterResultShape(): void
    {
        $redis = $this->maxOneRedisConnection('test_cluster_watch_conflict');
        $other = $this->maxOneRedisConnection('test_cluster_watch_conflict_other');
        $key = '{cluster-watch-conflict}:key';
        $redis->set($key, 'before');

        $this->assertTrue($redis->watch($key));
        $watchedClient = $this->nativeClient($redis);
        $other->set($key, 'changed');
        $result = $redis->transaction(static function (RedisCluster $transaction) use ($key): void {
            $transaction->set($key, 'after');
        });

        // PhpRedis currently returns one false result per queued command instead of false for a Cluster WATCH conflict.
        $this->assertSame([false], $result);

        $redis->releaseContextConnection();

        $this->assertSame($watchedClient, $this->nativeClient($redis));
        $this->assertSame('changed', $redis->get($key));
    }

    public function testHashSetAndSortedSetScansUseNativeClusterSignatures(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            'test_cluster_data_structure_scans',
            ['prefix' => ''],
        ));
        $hashKey = '{cluster-scans}:hash';
        $setKey = '{cluster-scans}:set';
        $sortedSetKey = '{cluster-scans}:sorted-set';

        $redis->hset($hashKey, 'field:1', 'one', 'field:2', 'two');
        $redis->sadd($setKey, 'member:1', 'member:2');
        $redis->zadd($sortedSetKey, 1.0, 'sorted:1', 2.0, 'sorted:2');

        $cursor = null;
        $hash = [];
        while (($chunk = $redis->hscan($hashKey, $cursor, '*')) !== false) {
            [$cursor, $fields] = $chunk;
            $hash = array_merge($hash, $fields);
        }
        ksort($hash);

        $cursor = null;
        $set = [];
        while (($chunk = $redis->sscan($setKey, $cursor, '*')) !== false) {
            [$cursor, $members] = $chunk;
            $set = array_merge($set, $members);
        }
        sort($set);

        $cursor = null;
        $sortedSet = [];
        while (($chunk = $redis->zscan($sortedSetKey, $cursor, '*')) !== false) {
            [$cursor, $members] = $chunk;
            $sortedSet = array_merge($sortedSet, $members);
        }
        ksort($sortedSet);

        $this->assertSame(['field:1' => 'one', 'field:2' => 'two'], $hash);
        $this->assertSame(['member:1', 'member:2'], $set);
        $this->assertSame(['sorted:1' => 1.0, 'sorted:2' => 2.0], $sortedSet);
    }

    private function maxOneRedisConnection(string $name): RedisProxy
    {
        return Redis::connection($this->createRedisConnectionWithOptions(
            $name,
            ['prefix' => ''],
            maxConnections: 1,
        ));
    }

    private function nativeClient(RedisProxy $redis): RedisCluster
    {
        return $redis->withConnection(function (RedisConnection $connection): RedisCluster {
            $client = $connection->client();

            $this->assertInstanceOf(RedisCluster::class, $client);

            return $client;
        });
    }

    /**
     * Find keys from different slots served by the same live master.
     *
     * @return array{string, string}
     */
    private function sameMasterDifferentSlotKeys(RedisCluster $client): array
    {
        $masters = $client->_masters();
        $this->assertNotEmpty($masters);

        $slotRanges = $client->rawCommand($masters[0], 'CLUSTER', 'SLOTS');
        $this->assertIsArray($slotRanges);

        $candidateByRange = [];

        for ($index = 0; $index < 64; ++$index) {
            $key = "{cluster-transaction-{$index}}:key";
            $slot = $client->rawCommand($masters[0], 'CLUSTER', 'KEYSLOT', $key);
            $this->assertIsInt($slot);

            foreach ($slotRanges as $rangeIndex => $slotRange) {
                if ($slot < $slotRange[0] || $slot > $slotRange[1]) {
                    continue;
                }

                if (isset($candidateByRange[$rangeIndex]) && $candidateByRange[$rangeIndex][1] !== $slot) {
                    return [$candidateByRange[$rangeIndex][0], $key];
                }

                $candidateByRange[$rangeIndex] = [$key, $slot];

                break;
            }
        }

        $this->fail('Unable to find two different slots served by one Redis Cluster master.');
    }
}
