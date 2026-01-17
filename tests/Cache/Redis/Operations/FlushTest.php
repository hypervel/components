<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;

/**
 * Tests for the Flush operation.
 *
 * @internal
 * @coversNothing
 */
class FlushTest extends TestCase
{
    use MocksRedisConnections;

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
