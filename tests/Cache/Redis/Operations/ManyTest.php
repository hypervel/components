<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;

/**
 * Tests for the Many operation.
 *
 * @internal
 * @coversNothing
 */
class ManyTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testRedisMultipleValuesAreReturned(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('mget')
            ->once()
            ->with(['prefix:foo', 'prefix:fizz', 'prefix:norf', 'prefix:null'])
            ->andReturn([
                serialize('bar'),
                serialize('buzz'),
                serialize('quz'),
                null,
            ]);

        $redis = $this->createStore($connection);
        $results = $redis->many(['foo', 'fizz', 'norf', 'null']);

        $this->assertSame('bar', $results['foo']);
        $this->assertSame('buzz', $results['fizz']);
        $this->assertSame('quz', $results['norf']);
        $this->assertNull($results['null']);
    }

    /**
     * @test
     */
    public function testManyReturnsEmptyArrayForEmptyKeys(): void
    {
        $connection = $this->mockConnection();

        $redis = $this->createStore($connection);
        $results = $redis->many([]);

        $this->assertSame([], $results);
    }

    /**
     * @test
     */
    public function testManyMaintainsKeyIndexMapping(): void
    {
        $connection = $this->mockConnection();
        // Return values in same order as requested
        $connection->shouldReceive('mget')
            ->once()
            ->with(['prefix:a', 'prefix:b', 'prefix:c'])
            ->andReturn([
                serialize('value_a'),
                null,
                serialize('value_c'),
            ]);

        $redis = $this->createStore($connection);
        $results = $redis->many(['a', 'b', 'c']);

        // Verify correct mapping
        $this->assertSame('value_a', $results['a']);
        $this->assertNull($results['b']);
        $this->assertSame('value_c', $results['c']);
        $this->assertCount(3, $results);
    }
}
