<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Forever operation (union tags).
 *
 * @internal
 * @coversNothing
 */
class ForeverTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testForeverWithTagsUsesLuaScript(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                // Forever uses SET (no TTL), HSET (no expiration), ZADD with max expiry
                $this->assertStringContainsString("redis.call('SET'", $script);
                $this->assertStringContainsString('HSET', $script);
                $this->assertStringContainsString('253402300799', $script); // MAX_EXPIRY
                $this->assertCount(2, $keys);

                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->forever()->execute('foo', 'bar', ['users']);
        $this->assertTrue($result);
    }
}
