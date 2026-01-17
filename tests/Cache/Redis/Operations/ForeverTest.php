<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;

/**
 * Tests for the Forever operation.
 *
 * @internal
 * @coversNothing
 */
class ForeverTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testStoreItemForeverProperlyCallsRedis(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', serialize('foo'))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->forever('foo', 'foo');
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testForeverWithNumericValue(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', 99)
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $this->assertTrue($redis->forever('foo', 99));
    }
}
