<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Matching\Argument;

/**
 * Tests for the Increment operation (union tags).
 */
class IncrementTest extends RedisCacheTestCase
{
    public function testIncrementWithTagsReturnsNewValue(): void
    {
        $connection = $this->mockConnection();

        // Lua script returns the incremented value
        $connection->expects('evalWithShaCache')
            ->with(Argument::capture($script), Argument::capture($keys), Argument::any())
            ->returns(15); // New value after increment

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->increment()->execute('counter', 5, ['stats']);
        $this->assertSame(15, $result);
        $this->assertStringContainsString('INCRBY', $script);
        $this->assertStringContainsString('TTL', $script);
        $this->assertStringContainsString((string) StoreContext::MAX_EXPIRY, $script);
        $this->assertCount(2, $keys);
    }

    public function testIncrementWithTagsUpdatesMetadataAfterAClusterCounterSucceeds(): void
    {
        [$redis, $client] = $this->createClusterStore(tagMode: 'any');

        $client->expects('multi')->times(2)->returns($client);
        $client->expects('incrBy')->with('prefix:counter', 5)->returns($client);
        $client->expects('ttl')->with('prefix:counter')->returns($client);
        $client->expects('exec')->times(2)->returns([15, -1], []);
        $client->expects('smembers')->with('prefix:counter:_any:tags')->returns([]);
        $client->expects('del')->with('prefix:counter:_any:tags')->returns($client);
        $client->expects('sadd')->with('prefix:counter:_any:tags', 'stats')->returns($client);
        $client->expects('hSet')
            ->with('prefix:_any:tag:stats:entries', 'counter', StoreContext::TAG_FIELD_VALUE)
            ->returns(1);
        $client->expects('zadd')
            ->with('prefix:_any:tag:registry', ['GT'], StoreContext::MAX_EXPIRY, 'stats')
            ->returns(1);

        $this->assertSame(15, $redis->anyTagOps()->increment()->execute('counter', 5, ['stats']));
    }

    public function testIncrementWithTagsReturnsFalseWithoutMetadataWhenClusterTransactionFails(): void
    {
        [$redis, $client] = $this->createClusterStore(tagMode: 'any');

        $client->expects('multi')->returns($client);
        $client->expects('incrBy')->with('prefix:counter', 5)->returns($client);
        $client->expects('ttl')->with('prefix:counter')->returns($client);
        $client->expects('exec')->returns(false);
        $client->expects('smembers')->never();
        $client->expects('sadd')->never();
        $client->expects('hSet')->never();
        $client->expects('zadd')->never();

        $this->assertFalse($redis->anyTagOps()->increment()->execute('counter', 5, ['stats']));
    }

    public function testIncrementWithTagsReturnsFalseWithoutMetadataWhenClusterCounterFails(): void
    {
        [$redis, $client] = $this->createClusterStore(tagMode: 'any');

        $client->expects('multi')->returns($client);
        $client->expects('incrBy')->with('prefix:counter', 5)->returns($client);
        $client->expects('ttl')->with('prefix:counter')->returns($client);
        $client->expects('exec')->returns([false, -1]);
        $client->expects('smembers')->never();
        $client->expects('sadd')->never();
        $client->expects('hSet')->never();
        $client->expects('zadd')->never();

        $this->assertFalse($redis->anyTagOps()->increment()->execute('counter', 5, ['stats']));
    }
}
