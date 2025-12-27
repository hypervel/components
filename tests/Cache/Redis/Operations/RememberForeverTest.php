<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;
use RuntimeException;

/**
 * Tests for the non-tagged RememberForever operation.
 *
 * Tests the single-connection optimization that performs GET and conditional
 * SET (without TTL) in a single pool checkout. Returns [value, wasHit] tuple.
 *
 * @internal
 * @coversNothing
 */
class RememberForeverTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testRememberForeverReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturn(serialize('cached_value'));

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->rememberForever('foo', fn () => 'new_value');

        $this->assertSame('cached_value', $value);
        $this->assertTrue($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverCallsCallbackOnCacheMiss(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturnNull();

        // Uses SET without TTL (not SETEX)
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', serialize('computed_value'))
            ->andReturn(true);

        $callCount = 0;
        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->rememberForever('foo', function () use (&$callCount) {
            ++$callCount;

            return 'computed_value';
        });

        $this->assertSame('computed_value', $value);
        $this->assertFalse($wasHit);
        $this->assertSame(1, $callCount);
    }

    /**
     * @test
     */
    public function testRememberForeverDoesNotCallCallbackOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturn(serialize('existing_value'));

        $callCount = 0;
        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->rememberForever('foo', function () use (&$callCount) {
            ++$callCount;

            return 'new_value';
        });

        $this->assertSame('existing_value', $value);
        $this->assertTrue($wasHit);
        $this->assertSame(0, $callCount, 'Callback should not be called on cache hit');
    }

    /**
     * @test
     */
    public function testRememberForeverWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // Numeric values are NOT serialized (optimization)
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', 42)
            ->andReturn(true);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->rememberForever('foo', fn () => 42);

        $this->assertSame(42, $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverWithArrayValue(): void
    {
        $connection = $this->mockConnection();
        $arrayValue = ['key' => 'value', 'nested' => ['a', 'b']];

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', serialize($arrayValue))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->rememberForever('foo', fn () => $arrayValue);

        $this->assertSame($arrayValue, $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $redis = $this->createStore($connection);
        $redis->rememberForever('foo', function () {
            throw new RuntimeException('Callback failed');
        });
    }

    /**
     * @test
     */
    public function testRememberForeverHandlesFalseReturnFromGet(): void
    {
        $connection = $this->mockConnection();

        // Redis returns false for non-existent keys
        $connection->shouldReceive('get')
            ->once()
            ->with('prefix:foo')
            ->andReturn(false);

        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', serialize('computed'))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->rememberForever('foo', fn () => 'computed');

        $this->assertSame('computed', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverWithEmptyStringValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', serialize(''))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->rememberForever('foo', fn () => '');

        $this->assertSame('', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverWithZeroValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        // Zero is numeric, not serialized
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', 0)
            ->andReturn(true);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->rememberForever('foo', fn () => 0);

        $this->assertSame(0, $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverWithNullReturnedFromCallback(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', serialize(null))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->rememberForever('foo', fn () => null);

        $this->assertNull($value);
        $this->assertFalse($wasHit);
    }
}
