<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Forget operation.
 */
class ForgetTest extends RedisCacheTestCase
{
    public function testForgetReturnsTrueWhenKeyExists(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('del')->with('prefix:foo')->returns(1);

        $redis = $this->createStore($connection);
        $this->assertTrue($redis->forget('foo'));
    }

    public function testForgetReturnsFalseWhenKeyDoesNotExist(): void
    {
        // Redis del() returns 0 when key doesn't exist, cast to bool = false
        $connection = $this->mockConnection();
        $connection->client()->expects('del')->with('prefix:nonexistent')->returns(0);

        $redis = $this->createStore($connection);
        $this->assertFalse($redis->forget('nonexistent'));
    }
}
