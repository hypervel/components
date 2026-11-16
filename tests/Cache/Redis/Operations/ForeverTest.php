<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Forever operation.
 */
class ForeverTest extends RedisCacheTestCase
{
    public function testStoreItemForeverProperlyCallsRedis(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('set')
            ->with('prefix:foo', serialize('foo'))
            ->returns(true);

        $redis = $this->createStore($connection);
        $result = $redis->forever('foo', 'foo');
        $this->assertTrue($result);
    }

    public function testForeverWithNumericValue(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('set')
            ->with('prefix:foo', 99)
            ->returns(true);

        $redis = $this->createStore($connection);
        $this->assertTrue($redis->forever('foo', 99));
    }
}
