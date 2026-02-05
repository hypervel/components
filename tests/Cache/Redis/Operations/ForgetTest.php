<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Forget operation.
 *
 * @internal
 * @coversNothing
 */
class ForgetTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testForgetReturnsTrueWhenKeyExists(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('del')->once()->with('prefix:foo')->andReturn(1);

        $redis = $this->createStore($connection);
        $this->assertTrue($redis->forget('foo'));
    }

    /**
     * @test
     */
    public function testForgetReturnsFalseWhenKeyDoesNotExist(): void
    {
        // Redis del() returns 0 when key doesn't exist, cast to bool = false
        $connection = $this->mockConnection();
        $connection->shouldReceive('del')->once()->with('prefix:nonexistent')->andReturn(0);

        $redis = $this->createStore($connection);
        $this->assertFalse($redis->forget('nonexistent'));
    }
}
