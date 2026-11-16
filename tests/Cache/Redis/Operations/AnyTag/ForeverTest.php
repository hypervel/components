<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Matching\Argument;

/**
 * Tests for the Forever operation (union tags).
 */
class ForeverTest extends RedisCacheTestCase
{
    public function testForeverWithTagsUsesLuaScript(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(Argument::all(function ($script, $keys, $args) {
                // Forever uses SET (no TTL), HSET (no expiration), ZADD with max expiry
                $this->assertStringContainsString("redis.call('SET'", $script);
                $this->assertStringContainsString('HSET', $script);
                $this->assertStringContainsString((string) StoreContext::MAX_EXPIRY, $script);
                $this->assertCount(2, $keys);

                return true;
            }))
            ->returns(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->forever()->execute('foo', 'bar', ['users']);
        $this->assertTrue($result);
    }
}
