<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Matching\Argument;

/**
 * Tests for the Decrement operation (union tags).
 */
class DecrementTest extends RedisCacheTestCase
{
    public function testDecrementWithTagsReturnsNewValue(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(Argument::all(function ($script, $keys, $args) {
                $this->assertStringContainsString('DECRBY', $script);
                $this->assertStringContainsString('TTL', $script);
                $this->assertStringContainsString((string) StoreContext::MAX_EXPIRY, $script);
                $this->assertCount(2, $keys);

                return true;
            }))
            ->returns(5); // New value after decrement

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->decrement()->execute('counter', 5, ['stats']);
        $this->assertSame(5, $result);
    }

    public function testDecrementWithTagsUpdatesMetadataAfterAClusterCounterSucceeds(): void
    {
        [$redis, $client] = $this->createClusterStore(tagMode: 'any');

        $client->expects('multi')->times(2)->returns($client);
        $client->expects('decrBy')->with('prefix:counter', 5)->returns($client);
        $client->expects('ttl')->with('prefix:counter')->returns($client);
        $client->expects('exec')->times(2)->returns([5, -1], []);
        $client->expects('smembers')->with('prefix:counter:_any:tags')->returns([]);
        $client->expects('del')->with('prefix:counter:_any:tags')->returns($client);
        $client->expects('sadd')->with('prefix:counter:_any:tags', 'stats')->returns($client);
        $client->expects('hSet')
            ->with('prefix:_any:tag:stats:entries', 'counter', StoreContext::TAG_FIELD_VALUE)
            ->returns(1);
        $client->expects('zadd')
            ->with('prefix:_any:tag:registry', ['GT'], StoreContext::MAX_EXPIRY, 'stats')
            ->returns(1);

        $this->assertSame(5, $redis->anyTagOps()->decrement()->execute('counter', 5, ['stats']));
    }

    public function testDecrementWithTagsReturnsFalseWithoutMetadataWhenClusterTransactionFails(): void
    {
        [$redis, $client] = $this->createClusterStore(tagMode: 'any');

        $client->expects('multi')->returns($client);
        $client->expects('decrBy')->with('prefix:counter', 5)->returns($client);
        $client->expects('ttl')->with('prefix:counter')->returns($client);
        $client->expects('exec')->returns(false);
        $client->expects('smembers')->never();
        $client->expects('sadd')->never();
        $client->expects('hSet')->never();
        $client->expects('zadd')->never();

        $this->assertFalse($redis->anyTagOps()->decrement()->execute('counter', 5, ['stats']));
    }

    public function testDecrementWithTagsReturnsFalseWithoutMetadataWhenClusterCounterFails(): void
    {
        [$redis, $client] = $this->createClusterStore(tagMode: 'any');

        $client->expects('multi')->returns($client);
        $client->expects('decrBy')->with('prefix:counter', 5)->returns($client);
        $client->expects('ttl')->with('prefix:counter')->returns($client);
        $client->expects('exec')->returns([false, -1]);
        $client->expects('smembers')->never();
        $client->expects('sadd')->never();
        $client->expects('hSet')->never();
        $client->expects('zadd')->never();

        $this->assertFalse($redis->anyTagOps()->decrement()->execute('counter', 5, ['stats']));
    }
}
