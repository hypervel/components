<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Flush operation.
 *
 * @internal
 * @coversNothing
 */
class FlushTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testFlushesCached(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('flushdb')->once()->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->flush();
        $this->assertTrue($result);
    }
}
