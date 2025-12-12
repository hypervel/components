<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;
use RuntimeException;

/**
 * Tests for the non-tagged Remember operation.
 *
 * Tests the single-connection optimization that performs GET and conditional
 * SETEX in a single pool checkout.
 *
 * @internal
 * @coversNothing
 */
class RememberTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testRememberReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturn(serialize('cached_value'));

        $redis = $this->createStore($connection);
        $result = $redis->remember('foo', 60, fn () => 'new_value');

        $this->assertSame('cached_value', $result);
    }

    /**
     * @test
     */
    public function testRememberCallsCallbackOnCacheMiss(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturnNull();

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:foo', 60, serialize('computed_value'))
            ->andReturn(true);

        $callCount = 0;
        $redis = $this->createStore($connection);
        $result = $redis->remember('foo', 60, function () use (&$callCount) {
            $callCount++;

            return 'computed_value';
        });

        $this->assertSame('computed_value', $result);
        $this->assertSame(1, $callCount);
    }

    /**
     * @test
     */
    public function testRememberDoesNotCallCallbackOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturn(serialize('existing_value'));

        $callCount = 0;
        $redis = $this->createStore($connection);
        $result = $redis->remember('foo', 60, function () use (&$callCount) {
            $callCount++;

            return 'new_value';
        });

        $this->assertSame('existing_value', $result);
        $this->assertSame(0, $callCount, 'Callback should not be called on cache hit');
    }

    /**
     * @test
     */
    public function testRememberEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // TTL should be at least 1 even when 0 is passed
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:foo', 1, serialize('bar'))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->remember('foo', 0, fn () => 'bar');
    }

    /**
     * @test
     */
    public function testRememberWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // Numeric values are NOT serialized (optimization)
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:foo', 60, 42)
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->remember('foo', 60, fn () => 42);

        $this->assertSame(42, $result);
    }

    /**
     * @test
     */
    public function testRememberWithArrayValue(): void
    {
        $connection = $this->mockConnection();
        $value = ['key' => 'value', 'nested' => ['a', 'b']];

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:foo', 120, serialize($value))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->remember('foo', 120, fn () => $value);

        $this->assertSame($value, $result);
    }

    /**
     * @test
     */
    public function testRememberWithObjectValue(): void
    {
        $connection = $this->mockConnection();
        $value = (object) ['name' => 'test'];

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:foo', 60, serialize($value))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->remember('foo', 60, fn () => $value);

        $this->assertEquals($value, $result);
    }

    /**
     * @test
     */
    public function testRememberPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $redis = $this->createStore($connection);
        $redis->remember('foo', 60, function () {
            throw new RuntimeException('Callback failed');
        });
    }

    /**
     * @test
     */
    public function testRememberHandlesFalseReturnFromGet(): void
    {
        $connection = $this->mockConnection();

        // Redis returns false for non-existent keys
        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturn(false);

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:foo', 60, serialize('computed'))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->remember('foo', 60, fn () => 'computed');

        $this->assertSame('computed', $result);
    }

    /**
     * @test
     */
    public function testRememberWithEmptyStringValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:foo', 60, serialize(''))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->remember('foo', 60, fn () => '');

        $this->assertSame('', $result);
    }

    /**
     * @test
     */
    public function testRememberWithZeroValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // Zero is numeric, not serialized
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:foo', 60, 0)
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->remember('foo', 60, fn () => 0);

        $this->assertSame(0, $result);
    }
}
