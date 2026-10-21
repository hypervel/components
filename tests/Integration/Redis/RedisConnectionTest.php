<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Exception;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use Redis as PhpRedis;
use RedisCluster;

class RedisConnectionTest extends TestCase
{
    use InteractsWithRedis;

    /**
     * Enable command events on the test connections.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.redis.default.events', true);
    }

    public function testItSetsValuesWithExpiry(): void
    {
        foreach ($this->connections() as $redis) {
            $this->assertTrue($redis->set('one', 'mohamed', 'EX', 5, 'NX'));
            $this->assertSame('mohamed', $redis->get('one'));
            $this->assertGreaterThan(0, $redis->ttl('one'));
            $this->assertLessThanOrEqual(5, $redis->ttl('one'));

            // It doesn't override when NX mode
            $this->assertFalse($redis->set('one', 'taylor', 'EX', 5, 'NX'));
            $this->assertSame('mohamed', $redis->get('one'));

            // It overrides when XX mode
            $redis->set('one', 'taylor', 'EX', 5, 'XX');
            $this->assertSame('taylor', $redis->get('one'));

            // It fails if XX mode is on and key doesn't exist
            $redis->set('two', 'taylor', 'PX', 5, 'XX');
            $this->assertNull($redis->get('two'));

            $redis->set('three', 'mohamed', 'PX', 5000);
            $this->assertSame('mohamed', $redis->get('three'));
            $this->assertGreaterThan(0, $redis->ttl('three'));
            $this->assertGreaterThan(0, $redis->pttl('three'));

            $redis->flushdb();
        }
    }

    public function testItDeletesKeys(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->set('one', 'mohamed');
            $redis->set('two', 'mohamed');
            $redis->set('three', 'mohamed');

            $redis->del('one');
            $this->assertNull($redis->get('one'));
            $this->assertNotNull($redis->get('two'));
            $this->assertNotNull($redis->get('three'));

            $redis->del('two', 'three');
            $this->assertNull($redis->get('two'));
            $this->assertNull($redis->get('three'));

            $redis->flushdb();
        }
    }

    public function testItChecksForExistence(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->set('one', 'mohamed');
            $redis->set('two', 'mohamed');

            $this->assertSame(1, $redis->exists('one'));
            $this->assertSame(0, $redis->exists('nothing'));
            $this->assertSame(2, $redis->exists('one', 'two'));
            $this->assertSame(2, $redis->exists('one', 'two', 'nothing'));

            $redis->flushdb();
        }
    }

    public function testItExpiresKeys(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->set('one', 'mohamed');
            $this->assertSame(-1, $redis->ttl('one'));
            $this->assertTrue($redis->expire('one', 10));
            $this->assertGreaterThan(0, $redis->ttl('one'));

            $this->assertFalse($redis->expire('nothing', 10));

            $redis->set('two', 'mohamed');
            $this->assertSame(-1, $redis->ttl('two'));
            $this->assertTrue($redis->pexpire('two', 10000));
            $this->assertGreaterThan(0, $redis->pttl('two'));

            $this->assertFalse($redis->pexpire('nothing', 10000));

            $redis->flushdb();
        }
    }

    public function testItRenamesKeys(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->set('one', 'mohamed');
            $redis->rename('one', 'two');
            $this->assertNull($redis->get('one'));
            $this->assertSame('mohamed', $redis->get('two'));

            $redis->set('three', 'adam');
            $redis->renamenx('two', 'three');
            $this->assertSame('mohamed', $redis->get('two'));
            $this->assertSame('adam', $redis->get('three'));

            $redis->renamenx('two', 'four');
            $this->assertNull($redis->get('two'));
            $this->assertSame('mohamed', $redis->get('four'));
            $this->assertSame('adam', $redis->get('three'));

            $redis->flushdb();
        }
    }

    public function testItAddsMembersToSortedSet(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set', 1, 'mohamed');
            $this->assertSame(1, $redis->zcard('set'));

            $this->assertSame(2, $redis->zadd('set', 2, 'taylor', 3, 'adam'));
            $this->assertSame(3, $redis->zcard('set'));
            $this->assertSame(2.0, $redis->zscore('set', 'taylor'));
            $this->assertSame(3.0, $redis->zscore('set', 'adam'));

            $this->assertSame(2, $redis->zadd('set', ['jeffrey' => 4, 'matt' => 5]));
            $this->assertSame(5, $redis->zcard('set'));
            $this->assertSame(4.0, $redis->zscore('set', 'jeffrey'));

            $redis->zadd('set', 'NX', 1, 'beric');
            $this->assertSame(6, $redis->zcard('set'));

            $redis->zadd('set', 'NX', ['joffrey' => 1]);
            $this->assertSame(7, $redis->zcard('set'));

            $redis->zadd('set', 'XX', ['ned' => 1]);
            $this->assertSame(7, $redis->zcard('set'));

            $this->assertSame(1, $redis->zadd('set', ['sansa' => 10]));
            $this->assertSame(0, $redis->zadd('set', 'XX', 'CH', ['arya' => 11]));

            $redis->zadd('set', ['mohamed' => 100]);
            $this->assertSame(100.0, $redis->zscore('set', 'mohamed'));

            $this->assertSame(2, $redis->zadd('set', 'NX', 1.0, 'robb', 2.0, 'jon'));
            $this->assertSame(1.0, $redis->zscore('set', 'robb'));
            $this->assertSame(2.0, $redis->zscore('set', 'jon'));

            $redis->flushdb();
        }
    }

    public function testItCountsMembersInSortedSet(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set', ['jeffrey' => 1, 'matt' => 10]);

            $this->assertSame(1, $redis->zcount('set', 1, 5));
            $this->assertSame(2, $redis->zcount('set', '-inf', '+inf'));
            $this->assertSame(2, $redis->zcard('set'));
            $this->assertSame(1, $redis->zcount('set', start: '(1', end: 10.5));

            $redis->flushdb();
        }
    }

    public function testItIncrementsScoreOfSortedSet(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set', ['jeffrey' => 1, 'matt' => 10]);
            $redis->zincrby('set', 2, 'jeffrey');
            $this->assertSame(3.0, $redis->zscore('set', 'jeffrey'));

            $redis->flushdb();
        }
    }

    public function testItSetsKeyIfNotExists(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->set('name', 'mohamed');

            $this->assertSame(0, $redis->setnx('name', 'taylor'));
            $this->assertSame('mohamed', $redis->get('name'));

            $this->assertSame(1, $redis->setnx('boss', 'taylor'));
            $this->assertSame('taylor', $redis->get('boss'));

            $redis->flushdb();
        }
    }

    public function testItSetsHashFieldIfNotExists(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->hset('person', 'name', 'mohamed');

            $this->assertSame(0, $redis->hsetnx('person', 'name', 'taylor'));
            $this->assertSame('mohamed', $redis->hget('person', 'name'));

            $this->assertSame(1, $redis->hsetnx('person', 'boss', 'taylor'));
            $this->assertSame('taylor', $redis->hget('person', 'boss'));

            $redis->flushdb();
        }
    }

    public function testItCalculatesIntersectionOfSortedSetsAndStores(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set1', ['jeffrey' => 1, 'matt' => 2, 'taylor' => 3]);
            $redis->zadd('set2', ['jeffrey' => 2, 'matt' => 3]);

            $redis->zinterstore('output', ['set1', 'set2']);
            $this->assertSame(2, $redis->zcard('output'));
            $this->assertSame(3.0, $redis->zscore('output', 'jeffrey'));
            $this->assertSame(5.0, $redis->zscore('output', 'matt'));

            $redis->zinterstore('output2', ['set1', 'set2'], [
                'weights' => [3, 2],
                'aggregate' => 'sum',
            ]);
            $this->assertSame(7.0, $redis->zscore('output2', 'jeffrey'));
            $this->assertSame(12.0, $redis->zscore('output2', 'matt'));

            $redis->zinterstore('output3', ['set1', 'set2'], [
                'weights' => [3, 2],
                'aggregate' => 'min',
            ]);
            $this->assertSame(3.0, $redis->zscore('output3', 'jeffrey'));
            $this->assertSame(6.0, $redis->zscore('output3', 'matt'));

            $redis->flushdb();
        }
    }

    public function testItCalculatesUnionOfSortedSetsAndStores(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set1', ['jeffrey' => 1, 'matt' => 2, 'taylor' => 3]);
            $redis->zadd('set2', ['jeffrey' => 2, 'matt' => 3]);

            $redis->zunionstore('output', ['set1', 'set2']);
            $this->assertSame(3, $redis->zcard('output'));
            $this->assertSame(3.0, $redis->zscore('output', 'jeffrey'));
            $this->assertSame(5.0, $redis->zscore('output', 'matt'));
            $this->assertSame(3.0, $redis->zscore('output', 'taylor'));

            $redis->zunionstore('output2', ['set1', 'set2'], [
                'weights' => [3, 2],
                'aggregate' => 'sum',
            ]);
            $this->assertSame(7.0, $redis->zscore('output2', 'jeffrey'));
            $this->assertSame(12.0, $redis->zscore('output2', 'matt'));
            $this->assertSame(9.0, $redis->zscore('output2', 'taylor'));

            $redis->zunionstore('output3', ['set1', 'set2'], [
                'weights' => [3, 2],
                'aggregate' => 'min',
            ]);
            $this->assertSame(3.0, $redis->zscore('output3', 'jeffrey'));
            $this->assertSame(6.0, $redis->zscore('output3', 'matt'));
            $this->assertSame(9.0, $redis->zscore('output3', 'taylor'));

            $redis->flushdb();
        }
    }

    public function testItReturnsRangeInSortedSet(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set', ['jeffrey' => 1, 'matt' => 5, 'taylor' => 10]);
            $this->assertSame(['jeffrey', 'matt'], $redis->zrange('set', 0, 1));
            $this->assertSame(['jeffrey', 'matt', 'taylor'], $redis->zrange('set', 0, -1));
            $this->assertSame(['jeffrey' => 1.0, 'matt' => 5.0], $redis->zrange('set', 0, 1, true));

            $redis->flushdb();
        }
    }

    public function testItReturnsRevRangeInSortedSet(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set', ['jeffrey' => 1, 'matt' => 5, 'taylor' => 10]);
            $this->assertSame(['taylor', 'matt'], $redis->ZREVRANGE('set', 0, 1));
            $this->assertSame(['taylor', 'matt', 'jeffrey'], $redis->ZREVRANGE('set', 0, -1));
            $this->assertSame(['taylor' => 10.0, 'matt' => 5.0], $redis->ZREVRANGE('set', 0, 1, true));

            $redis->flushdb();
        }
    }

    public function testItReturnsRangeByScoreInSortedSet(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set', ['jeffrey' => 1, 'matt' => 5, 'taylor' => 10]);
            $this->assertSame(['jeffrey'], $redis->zrangebyscore('set', 0, 3));
            $this->assertSame(['matt' => 5.0, 'taylor' => 10.0], $redis->zrangebyscore('set', 0, 11, [
                'withscores' => true,
                'limit' => [
                    'offset' => 1,
                    'count' => 2,
                ],
            ]));
            $this->assertSame(['matt' => 5.0, 'taylor' => 10.0], $redis->zrangebyscore('set', 0, 11, [
                'withscores' => true,
                'limit' => [1, 2],
            ]));
            $this->assertSame(['matt', 'taylor'], $redis->zrangebyscore('set', 0, 11, [
                'limit' => ['offset' => 1, 'count' => 2],
            ]));

            $redis->flushdb();
        }
    }

    public function testItReturnsRevRangeByScoreInSortedSet(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set', ['jeffrey' => 1, 'matt' => 5, 'taylor' => 10]);
            $this->assertSame(['taylor'], $redis->ZREVRANGEBYSCORE('set', 10, 6));
            $this->assertSame(['matt' => 5.0, 'jeffrey' => 1.0], $redis->ZREVRANGEBYSCORE('set', 10, 0, [
                'withscores' => true,
                'limit' => [
                    'offset' => 1,
                    'count' => 2,
                ],
            ]));
            $this->assertSame(['matt' => 5.0, 'jeffrey' => 1.0], $redis->ZREVRANGEBYSCORE('set', 10, 0, [
                'withscores' => true,
                'limit' => [1, 2],
            ]));
            $this->assertSame(['matt', 'jeffrey'], $redis->ZREVRANGEBYSCORE('set', 10, 0, [
                'limit' => ['offset' => 1, 'count' => 2],
            ]));

            $redis->flushdb();
        }
    }

    public function testItReturnsRankInSortedSet(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set', ['jeffrey' => 1, 'matt' => 5, 'taylor' => 10]);

            $this->assertSame(0, $redis->zrank('set', 'jeffrey'));
            $this->assertSame(2, $redis->zrank('set', 'taylor'));

            $redis->flushdb();
        }
    }

    public function testItReturnsScoreInSortedSet(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set', ['jeffrey' => 1, 'matt' => 5, 'taylor' => 10]);

            $this->assertSame(1.0, $redis->zscore('set', 'jeffrey'));
            $this->assertSame(10.0, $redis->zscore('set', 'taylor'));

            $redis->flushdb();
        }
    }

    public function testItRemovesMembersInSortedSet(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set', ['jeffrey' => 1, 'matt' => 5, 'taylor' => 10, 'adam' => 11]);

            $redis->zrem('set', 'jeffrey');
            $this->assertSame(3, $redis->zcard('set'));

            $redis->zrem('set', 'matt', 'adam');
            $this->assertSame(1, $redis->zcard('set'));

            $redis->flushdb();
        }
    }

    public function testItRemovesMembersByScoreInSortedSet(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set', ['jeffrey' => 1, 'matt' => 5, 'taylor' => 10, 'adam' => 11]);
            $this->assertSame(3, $redis->ZREMRANGEBYSCORE('set', 5, '+inf'));
            $this->assertSame(1, $redis->zcard('set'));

            $redis->zadd('set', ['fractional' => 1.5, 'upper' => 2]);
            $bounds = $this->usingRedisCluster() ? ['min' => 1.5, 'max' => '(2'] : ['start' => 1.5, 'end' => '(2'];
            $this->assertSame(1, $redis->zRemRangeByScore('set', ...$bounds));
            $this->assertSame(['jeffrey', 'upper'], $redis->zrange('set', 0, -1));

            $redis->flushdb();
        }
    }

    public function testItRemovesMembersByRankInSortedSet(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->zadd('set', ['jeffrey' => 1, 'matt' => 5, 'taylor' => 10, 'adam' => 11]);
            $redis->ZREMRANGEBYRANK('set', 1, -1);
            $this->assertSame(1, $redis->zcard('set'));

            $redis->flushdb();
        }
    }

    public function testItSetsMultipleHashFields(): void
    {
        foreach ($this->connections() as $redis) {
            $this->assertTrue($redis->hmset('hash', ['name' => 'mohamed', 'hobby' => 'diving']));
            $this->assertSame(['name' => 'mohamed', 'hobby' => 'diving'], $redis->hgetall('hash'));

            $this->assertTrue($redis->hmset('hash2', 'name', 'mohamed', 'hobby', 'diving'));
            $this->assertSame(['name' => 'mohamed', 'hobby' => 'diving'], $redis->hgetall('hash2'));

            $redis->flushdb();
        }
    }

    public function testItGetsMultipleHashFields(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->hmset('hash', ['name' => 'mohamed', 'hobby' => 'diving']);

            $this->assertSame(
                ['mohamed', 'diving'],
                $redis->hmget('hash', 'name', 'hobby')
            );

            $this->assertSame(
                ['mohamed', 'diving'],
                $redis->hmget('hash', ['name', 'hobby'])
            );

            $redis->flushdb();
        }
    }

    public function testItGetsMultipleKeys(): void
    {
        $valueSet = ['name' => 'mohamed', 'hobby' => 'diving'];

        foreach ($this->connections() as $redis) {
            $redis->mset($valueSet);

            $this->assertSame(
                array_values($valueSet),
                $redis->mget(array_keys($valueSet))
            );
            $this->assertSame(['mohamed', null, 'diving'], $redis->mget(['name', 'missing', 'hobby']));
            $this->assertSame([], $redis->mget([]));

            $redis->flushdb();
        }
    }

    public function testItFlushes(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->set('name', 'Till');
            $this->assertSame(1, $redis->exists('name'));

            $redis->flushdb();
            $this->assertSame(0, $redis->exists('name'));
        }
    }

    public function testItFlushesAsynchronous(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->set('name', 'Till');
            $this->assertSame(1, $redis->exists('name'));

            $this->assertTrue($redis->flushdb('ASYNC'));
            $this->assertSame(0, $redis->exists('name'));
        }
    }

    public function testItRunsEval(): void
    {
        foreach ($this->connections() as $redis) {
            // User must decide what needs to be serialized and compressed.
            $redis->eval('redis.call("set", KEYS[1], ARGV[1])', 1, 'name', ...$redis->pack(['mohamed']));

            $this->assertSame('mohamed', $redis->get('name'));
            $this->assertSame(
                $redis->pack(['mohamed'])[0],
                $redis->eval('return redis.call("GET", KEYS[1])', 1, 'name'),
            );

            $redis->flushdb();
        }
    }

    public function testItRunsPipes(): void
    {
        if ($this->usingRedisCluster()) {
            $this->markTestSkipped('PhpRedis does not support pipelines on Redis Cluster.');
        }

        foreach ($this->connections() as $redis) {
            $result = $redis->pipeline(function (PhpRedis $pipe): void {
                $pipe->set('test:pipeline:1', '1');
                $pipe->get('test:pipeline:1');
                $pipe->set('test:pipeline:2', '2');
                $pipe->get('test:pipeline:2');
            });

            $this->assertCount(4, $result);
            $this->assertSame('1', $result[1]);
            $this->assertSame('2', $result[3]);

            $redis->flushdb();
        }
    }

    public function testItRunsTransactions(): void
    {
        foreach ($this->connections() as $redis) {
            $result = $redis->transaction(function (PhpRedis|RedisCluster $pipe): void {
                $pipe->set('test:transaction:1', '1');
                $pipe->get('test:transaction:1');
                $pipe->set('test:transaction:2', '2');
                $pipe->get('test:transaction:2');
            });

            $this->assertCount(4, $result);
            $this->assertSame('1', $result[1]);
            $this->assertSame('2', $result[3]);

            $redis->flushdb();
        }
    }

    public function testItRunsRawCommand(): void
    {
        foreach ($this->connections() as $redis) {
            $key = $this->getPrefix($redis) . 'test:raw:1';
            $redis->executeRaw(['SET', $key, '1']);

            $this->assertSame(
                '1',
                $redis->executeRaw(['GET', $key])
            );
            $this->assertFalse($redis->executeRaw(['GET', $key . ':missing']));

            $redis->flushdb();
        }
    }

    public function testItDispatchesQueryEvent(): void
    {
        // REMOVED: Per-connection dispatcher replacement; pooled connections use the application's dispatcher.
        $events = [];
        $this->app->make(Dispatcher::class)->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        foreach ($this->connections() as $redis) {
            $events = [];
            $redis->get('foobar');

            $this->assertCount(1, $events);
            $this->assertSame('get', $events[0]->command);
            $this->assertSame(['foobar'], $events[0]->parameters);
            $this->assertSame($redis->getName(), $events[0]->connectionName);
            $this->assertInstanceOf(RedisConnection::class, $events[0]->connection);
            $this->assertSame($redis->getName(), $events[0]->connection->getName());
        }
    }

    public function testItPersistsConnection(): void
    {
        // REMOVED: Native persistent connections; the pool owns connection reuse.
        $redis = Redis::connection($this->createRedisConnectionWithOptions('test_persistence', [], maxConnections: 1));
        $first = $redis->withConnection(static fn (RedisConnection $connection): PhpRedis|RedisCluster => $connection->client());
        $second = $redis->withConnection(static fn (RedisConnection $connection): PhpRedis|RedisCluster => $connection->client());

        $this->assertSame($first, $second);
    }

    public function testItScansForKeys(): void
    {
        foreach ($this->connections() as $redis) {
            $initialKeys = ['test:scan:1', 'test:scan:2', 'test:scan:3', 'test:scan:4'];

            foreach ($initialKeys as $index => $key) {
                $redis->set($key, 'test');
                $initialKeys[$index] = $this->getPrefix($redis) . $key;
            }

            $iterator = null;
            $result = [];
            $pattern = $this->getPrefix($redis) . 'test:scan:*';

            while (($page = $redis->scan($iterator, ['match' => $pattern, 'count' => 2])) !== false) {
                [$iterator, $returnedKeys] = $page;

                foreach ($returnedKeys as $returnedKey) {
                    $this->assertContains($returnedKey, $initialKeys);
                    $result[] = $returnedKey;
                }
            }

            $result = array_values(array_unique($result));
            sort($result);
            $this->assertSame($initialKeys, $result);

            $redis->flushdb();
        }
    }

    public function testItZscansForKeys(): void
    {
        foreach ($this->connections() as $redis) {
            $members = ['test:zscan:1' => 100.0, 'test:zscan:2' => 200.0, 'test:zscan:3' => 300.0, 'test:zscan:4' => 400.0];

            foreach ($members as $member => $score) {
                $redis->zadd('set', $score, $member);
            }

            $iterator = null;
            $result = [];

            while (($page = $redis->zscan('set', $iterator, ['count' => 2])) !== false) {
                [$iterator, $returnedMembers] = $page;
                $result += $returnedMembers;
            }

            ksort($result);
            $this->assertSame($members, $result);

            $iterator = null;
            $result = [];
            while (($page = $redis->zscan('set', $iterator, ['match' => 'test:unmatch:*'])) !== false) {
                [$iterator, $returned] = $page;
                $result += $returned;
            }
            $this->assertSame([], $result);

            $iterator = null;
            $result = [];
            // MATCH sees encoded members; literal patterns apply only without serialization or compression.
            $options = ! $redis->serialized() && ! $redis->compressed()
                ? ['match' => 'test:zscan:*', 'count' => 5]
                : ['count' => 5];
            while (($page = $redis->zscan('set', $iterator, $options)) !== false) {
                [$iterator, $returned] = $page;
                $result += $returned;
            }
            ksort($result);
            $this->assertSame($members, $result);

            $redis->flushdb();
        }
    }

    public function testItHscansForKeys(): void
    {
        foreach ($this->connections() as $redis) {
            $fields = ['city' => 'London', 'hobby' => 'diving', 'language' => 'PHP', 'name' => 'mohamed'];

            foreach ($fields as $field => $value) {
                $redis->hset('hash', $field, $value);
            }

            $iterator = null;
            $result = [];

            while (($page = $redis->hscan('hash', $iterator, ['count' => 2])) !== false) {
                [$iterator, $returnedFields] = $page;
                $result += $returnedFields;
            }

            ksort($result);
            $this->assertSame($fields, $result);

            $iterator = null;
            $result = [];
            while (($page = $redis->hscan('hash', $iterator, ['match' => 'test:unmatch:*'])) !== false) {
                [$iterator, $returned] = $page;
                $result += $returned;
            }
            $this->assertSame([], $result);

            $iterator = null;
            $result = [];
            while (($page = $redis->hscan('hash', $iterator, ['match' => 'h*', 'count' => 5])) !== false) {
                [$iterator, $returned] = $page;
                $result += $returned;
            }
            $this->assertSame(['hobby' => 'diving'], $result);

            $redis->flushdb();
        }
    }

    public function testItSscansForKeys(): void
    {
        foreach ($this->connections() as $redis) {
            $members = ['test:sscan:1', 'test:sscan:2', 'test:sscan:3', 'test:sscan:4'];

            foreach ($members as $member) {
                $redis->sadd('set', $member);
            }

            $iterator = null;
            $result = [];

            while (($page = $redis->sscan('set', $iterator, ['count' => 2])) !== false) {
                [$iterator, $returnedMembers] = $page;

                foreach ($returnedMembers as $member) {
                    $this->assertContains($member, $members);
                    $result[] = $member;
                }
            }

            $result = array_values(array_unique($result));
            sort($result);
            $this->assertSame($members, $result);

            $iterator = null;
            $result = [];
            while (($page = $redis->sscan('set', $iterator, ['match' => 'test:unmatch:*'])) !== false) {
                [$iterator, $returned] = $page;
                array_push($result, ...$returned);
            }
            $this->assertSame([], $result);

            $iterator = null;
            $result = [];
            // MATCH sees encoded members; literal patterns apply only without serialization or compression.
            $options = ! $redis->serialized() && ! $redis->compressed()
                ? ['match' => 'test:sscan:*', 'count' => 5]
                : ['count' => 5];
            while (($page = $redis->sscan('set', $iterator, $options)) !== false) {
                [$iterator, $returned] = $page;
                array_push($result, ...$returned);
            }
            $result = array_values(array_unique($result));
            sort($result);
            $this->assertSame($members, $result);

            $redis->flushdb();
        }
    }

    public function testItSPopsForKeys(): void
    {
        foreach ($this->connections() as $redis) {
            $members = ['test:spop:1', 'test:spop:2', 'test:spop:3', 'test:spop:4'];

            foreach ($members as $member) {
                $redis->sadd('set', $member);
            }

            $result = $redis->spop('set');
            $this->assertIsNotArray($result);
            $this->assertContains($result, $members);

            $result = $redis->spop('set', 1);

            $this->assertIsArray($result);
            $this->assertCount(1, $result);

            $result = $redis->spop('set', 2);

            $this->assertIsArray($result);
            $this->assertCount(2, $result);
            $this->assertFalse($redis->spop('set'));

            $redis->flushdb();
        }
    }

    public function testPhpRedisScanOption(): void
    {
        foreach ($this->connections() as $redis) {
            $initialKeys = ['scan:retry:1', 'scan:retry:2', 'scan:retry:3', 'scan:retry:4'];
            foreach ($initialKeys as $index => $key) {
                $redis->set($key, 'value');
                $initialKeys[$index] = $this->getPrefix($redis) . $key;
            }

            $scanOptions = $redis->withConnection(static fn (RedisConnection $connection): int => $connection->client()->getOption(PhpRedis::OPT_SCAN));
            $iterator = null;
            $result = [];

            while (($page = $redis->scan($iterator, ['count' => 1])) !== false) {
                [$iterator, $returned] = $page;

                if (($scanOptions & PhpRedis::SCAN_RETRY) !== 0) {
                    $this->assertNotEmpty($returned);
                }
                array_push($result, ...$returned);
            }

            $result = array_values(array_unique($result));
            sort($result);
            $this->assertSame($initialKeys, $result);
            $redis->flushdb();
        }
    }

    /**
     * Get the native connection's key prefix while holding its lease.
     */
    private function getPrefix(RedisProxy $redis): string
    {
        return $redis->withConnection(static fn (RedisConnection $connection): string => $connection->client()->getOption(PhpRedis::OPT_PREFIX));
    }

    public function testMacroable(): void
    {
        RedisConnection::macro('foo', function (): string {
            return 'foo';
        });

        $this->assertSame('foo', Redis::connection()->foo());
    }

    public function testEvalWithMultipleKeysAndArgs(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // Set two keys, then use eval with 2 KEYS + 1 ARGV
        $redis->set('{eval}:k1', 'v1');
        $redis->set('{eval}:k2', 'v2');

        $result = $redis->eval(
            'return {redis.call("GET", KEYS[1]), redis.call("GET", KEYS[2]), ARGV[1]}',
            2,
            '{eval}:k1',
            '{eval}:k2',
            'extra_arg'
        );

        $this->assertSame(['v1', 'v2', 'extra_arg'], $result);
    }

    public function testEvalNormalizesNestedNilReplies(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $result = $redis->eval('return {1, {false, 2}}', 0);

        $this->assertSame([1, [false, 2]], $result);
    }

    public function testLremSwapsArguments(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->rpush('lrem_list', 'a');
        $redis->rpush('lrem_list', 'b');
        $redis->rpush('lrem_list', 'a');
        $redis->rpush('lrem_list', 'c');
        $redis->rpush('lrem_list', 'a');

        // Laravel-style: lrem(key, count, value)
        // Transform reorders to phpredis: lRem(key, value, count)
        $removed = $redis->lrem('lrem_list', 2, 'a');

        $this->assertSame(2, $removed);

        // Should have one 'a' remaining (removed from head)
        $remaining = $redis->lrange('lrem_list', 0, -1);
        $this->assertSame(['b', 'c', 'a'], $remaining);
    }

    public function testBlpopReturnsNullOnTimeout(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // blpop with 1 second timeout on empty list returns null (not empty array)
        $result = $redis->blpop('empty_list', 1);

        $this->assertNull($result);
    }

    public function testBrpopReturnsNullOnTimeout(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // brpop with 1 second timeout on empty list returns null (not empty array)
        $result = $redis->brpop('empty_list', 1);

        $this->assertNull($result);
    }

    public function testBlpopReturnsArrayOnSuccess(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->rpush('blpop_list', 'item1');

        $result = $redis->blpop('blpop_list', 1);

        $this->assertSame(['blpop_list', 'item1'], $result);
    }

    public function testPingUsesTheConfiguredTopology(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));

        $this->assertTrue($redis->ping());
    }

    public function testInfoHonorsTheServerSectionFilter(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $info = $redis->info('server');

        $this->assertIsArray($info);
        $this->assertArrayHasKey('redis_version', $info);
        $this->assertArrayNotHasKey('used_memory', $info);
    }

    public function testEvalshaLoadsAndExecutesScript(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->set('evalsha_key', 'evalsha_value');

        // Transform: evalsha(script, numKeys, key) → cached evalSha with an eval fallback.
        $result = $redis->evalsha('return redis.call("GET", KEYS[1])', 1, 'evalsha_key');

        $this->assertSame('evalsha_value', $result);
    }

    public function testStreamCommandsAcceptIntegerIdsAndThresholds(): void
    {
        foreach ($this->connections() as $redis) {
            $this->assertSame('1-0', $redis->xadd('stream', id: 1, values: ['name' => 'first']));
            $this->assertSame('2-0', $redis->xadd('stream', 2, ['name' => 'second']));

            $entries = ['1-0' => ['name' => 'first'], '2-0' => ['name' => 'second']];
            $this->assertSame($entries, $redis->xrange('stream', 0, 2));
            $this->assertSame($entries, $redis->xrange('stream', start: 0, end: '+'));
            $this->assertSame(array_reverse($entries, true), $redis->xrevrange('stream', 2, 0));

            $this->assertTrue($redis->xgroup('CREATE', 'stream', 'group', id_or_consumer: 0));
            $this->assertSame(
                [$this->getPrefix($redis) . 'stream' => $entries],
                $redis->xreadgroup('group', 'original', ['stream' => '>'], 2),
            );
            $this->assertSame(2, $redis->xpending('stream', 'group')[0]);
            $this->assertSame(2, $redis->xpending('stream', 'group', null, null)[0]);

            $pending = $redis->xpending('stream', 'group', start: 0, end: 2, count: 10);
            $this->assertSame(['1-0', '2-0'], array_column($pending, 0));
            $this->assertSame(['original', 'original'], array_column($pending, 1));

            $claimed = $redis->xautoclaim('stream', 'group', 'replacement', 0, start: 0);
            $this->assertSame('0-0', $claimed[0]);
            $this->assertSame($entries, $claimed[1]);
            $this->assertSame(
                ['replacement', 'replacement'],
                array_column($redis->xpending('stream', 'group', 0, '+', 10), 1),
            );

            $threshold = $this->usingRedisCluster() ? ['maxlen' => 1] : ['threshold' => 1];
            $this->assertSame(1, $redis->xtrim('stream', ...$threshold));
            $this->assertSame(['2-0' => ['name' => 'second']], $redis->xrange('stream', 0, '+'));

            $redis->flushdb();
        }
    }

    public function testItKeepsTheConnectionUsableWhenATransactionFails(): void
    {
        foreach ($this->connections() as $redis) {
            $redis->set('name', 'taylor');
            $exception = new Exception('Something went wrong.');

            try {
                $redis->transaction(function (PhpRedis|RedisCluster $transaction) use ($exception): never {
                    $transaction->set('name', 'mohamed');

                    throw $exception;
                });
                $this->fail('Expected the transaction callback exception to propagate.');
            } catch (Exception $caught) {
                $this->assertSame($exception, $caught);
            }

            $this->assertSame('taylor', $redis->get('name'));

            $redis->flushdb();
        }
    }

    public function testItKeepsTheConnectionUsableWhenAPipelineFails(): void
    {
        if ($this->usingRedisCluster()) {
            $this->markTestSkipped('PhpRedis does not support pipelines on Redis Cluster.');
        }

        foreach ($this->connections() as $redis) {
            $redis->set('name', 'taylor');
            $exception = new Exception('Something went wrong.');

            try {
                $redis->pipeline(function (PhpRedis $pipeline) use ($exception): never {
                    $pipeline->set('name', 'mohamed');

                    throw $exception;
                });
                $this->fail('Expected the pipeline callback exception to propagate.');
            } catch (Exception $caught) {
                $this->assertSame($exception, $caught);
            }

            $this->assertSame('taylor', $redis->get('name'));

            $redis->flushdb();
        }
    }

    /**
     * Get the configured PhpRedis connection variants.
     *
     * @return array<string, RedisProxy>
     */
    public function connections(): array
    {
        // REMOVED: Predis variants; Hypervel supports phpredis only.
        $configurations = [
            'phpredis' => ['options' => []],
            'serializer_json' => ['options' => ['serializer' => PhpRedis::SERIALIZER_JSON]],
        ];

        if (! $this->usingRedisCluster()) {
            $default = config('database.redis.default');
            $configurations['url'] = [
                'url' => "redis://{$default['host']}:{$default['port']}",
                'host' => 'overwrittenByUrl',
                'port' => 'overwrittenByUrl',
                'options' => [],
            ];
            // The connection pool owns persistence instead of native pconnect().
            $configurations['pooled'] = ['options' => []];
            $configurations['scan_retry'] = ['options' => ['scan' => PhpRedis::SCAN_RETRY]];
        }

        if (defined('Redis::COMPRESSION_LZF')) {
            $configurations['compression_lzf'] = [
                'name' => 'compression_lzf',
                'options' => ['compression' => PhpRedis::COMPRESSION_LZF],
            ];
        }

        if (defined('Redis::COMPRESSION_ZSTD')) {
            $configurations['compression_zstd'] = [
                'name' => 'compression_zstd',
                'options' => ['compression' => PhpRedis::COMPRESSION_ZSTD],
            ];
            $configurations['compression_zstd_default'] = [
                'name' => 'compression_zstd_default',
                'options' => [
                    'compression' => PhpRedis::COMPRESSION_ZSTD,
                    'compression_level' => PhpRedis::COMPRESSION_ZSTD_DEFAULT,
                ],
            ];
            $configurations['compression_zstd_max'] = [
                'name' => 'compression_zstd_max',
                'options' => [
                    'compression' => PhpRedis::COMPRESSION_ZSTD,
                    'compression_level' => PhpRedis::COMPRESSION_ZSTD_MAX,
                ],
            ];
        }

        if (defined('Redis::COMPRESSION_LZ4')) {
            $configurations['compression_lz4'] = [
                'name' => 'compression_lz4',
                'options' => ['compression' => PhpRedis::COMPRESSION_LZ4],
            ];
            $configurations['compression_lz4_default'] = [
                'name' => 'compression_lz4_default',
                'options' => [
                    'compression' => PhpRedis::COMPRESSION_LZ4,
                    'compression_level' => 0,
                ],
            ];
            $configurations['compression_lz4_min'] = [
                'name' => 'compression_lz4_min',
                'options' => [
                    'compression' => PhpRedis::COMPRESSION_LZ4,
                    'compression_level' => 1,
                ],
            ];
            $configurations['compression_lz4_max'] = [
                'name' => 'compression_lz4_max',
                'options' => [
                    'compression' => PhpRedis::COMPRESSION_LZ4,
                    'compression_level' => 12,
                ],
            ];
        }

        if ($this->usingRedisCluster()) {
            $compression = array_find_key($configurations, static fn (array $configuration): bool => isset($configuration['options']['compression']));
            $configurations = array_intersect_key($configurations, array_flip(array_filter(['phpredis', 'serializer_json', $compression])));
        }

        $connections = [];
        foreach ($configurations as $name => $configuration) {
            if ($this->usingRedisCluster()) {
                unset($configuration['name']);
            }

            // Variants share one isolated database; Cluster multi-key commands also need one slot.
            $configuration['options']['prefix'] = '{' . $name . '}:';
            $connectionName = $this->createRedisConnectionWithOptions('test_' . $name, $configuration['options']);
            config([
                "database.redis.{$connectionName}" => array_replace(
                    config("database.redis.{$connectionName}"),
                    $configuration,
                    ['prefix' => null, 'timeout' => 0.5],
                ),
            ]);

            $connections[$name] = Redis::connection($connectionName);
        }

        return $connections;
    }
}
