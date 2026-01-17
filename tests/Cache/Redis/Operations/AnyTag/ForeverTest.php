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
        $client = $connection->_mockClient;

        $client->shouldReceive('evalSha')
            ->once()
            ->andReturn(false);
        $client->shouldReceive('eval')
            ->once()
            ->withArgs(function ($script, $args, $numKeys) {
                // Forever uses SET (no TTL), HSET (no expiration), ZADD with max expiry
                $this->assertStringContainsString("redis.call('SET'", $script);
                $this->assertStringContainsString('HSET', $script);
                $this->assertStringContainsString('253402300799', $script); // MAX_EXPIRY
                $this->assertSame(2, $numKeys);

                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->forever()->execute('foo', 'bar', ['users']);
        $this->assertTrue($result);
    }
}
