<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Mockery as m;
use RuntimeException;

/**
 * Tests for the AllTag Remember operation.
 *
 * Tests the single-connection optimization that performs GET and conditional
 * tagged PUT (ZADD + SETEX) in a single pool checkout using pipeline or
 * sequential commands for cluster mode.
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
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->with('prefix:ns:foo')
            ->andReturn(serialize('cached_value'));

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->remember()->execute('ns:foo', 60, fn () => 'new_value', ['tag1:entries']);

        $this->assertSame('cached_value', $value);
        $this->assertTrue($wasHit);
    }

    /**
     * @test
     */
    public function testRememberCallsCallbackOnCacheMissUsingPipeline(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->with('prefix:ns:foo')
            ->andReturnNull();

        // Pipeline mode for non-cluster
        $pipeline = m::mock();
        $client->shouldReceive('pipeline')
            ->once()
            ->andReturn($pipeline);

        // ZADD for each tag
        $pipeline->shouldReceive('zadd')
            ->once()
            ->withArgs(function ($key, $score, $member) {
                $this->assertSame('prefix:tag1:entries', $key);
                $this->assertIsInt($score);
                $this->assertSame('ns:foo', $member);

                return true;
            });

        // SETEX for the value
        $pipeline->shouldReceive('setex')
            ->once()
            ->withArgs(function ($key, $ttl, $value) {
                $this->assertSame('prefix:ns:foo', $key);
                $this->assertSame(60, $ttl);
                $this->assertSame(serialize('computed_value'), $value);

                return true;
            });

        $pipeline->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $callCount = 0;
        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->remember()->execute('ns:foo', 60, function () use (&$callCount) {
            ++$callCount;

            return 'computed_value';
        }, ['tag1:entries']);

        $this->assertSame('computed_value', $value);
        $this->assertFalse($wasHit);
        $this->assertSame(1, $callCount);
    }

    /**
     * @test
     */
    public function testRememberDoesNotCallCallbackOnCacheHit(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->with('prefix:ns:foo')
            ->andReturn(serialize('existing_value'));

        $callCount = 0;
        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->remember()->execute('ns:foo', 60, function () use (&$callCount) {
            ++$callCount;

            return 'new_value';
        }, ['tag1:entries']);

        $this->assertSame('existing_value', $value);
        $this->assertTrue($wasHit);
        $this->assertSame(0, $callCount, 'Callback should not be called on cache hit');
    }

    /**
     * @test
     */
    public function testRememberWithMultipleTags(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $pipeline = m::mock();
        $client->shouldReceive('pipeline')
            ->once()
            ->andReturn($pipeline);

        // Should ZADD to each tag's sorted set
        $pipeline->shouldReceive('zadd')
            ->times(3)
            ->andReturn(1);

        $pipeline->shouldReceive('setex')
            ->once()
            ->andReturn(true);

        $pipeline->shouldReceive('exec')
            ->once()
            ->andReturn([1, 1, 1, true]);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->remember()->execute(
            'ns:foo',
            60,
            fn () => 'value',
            ['tag1:entries', 'tag2:entries', 'tag3:entries']
        );

        $this->assertSame('value', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $redis = $this->createStore($connection);
        $redis->allTagOps()->remember()->execute('ns:foo', 60, function () {
            throw new RuntimeException('Callback failed');
        }, ['tag1:entries']);
    }

    /**
     * @test
     */
    public function testRememberEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $pipeline = m::mock();
        $client->shouldReceive('pipeline')
            ->once()
            ->andReturn($pipeline);

        $pipeline->shouldReceive('zadd')
            ->once()
            ->andReturn(1);

        // TTL should be at least 1
        $pipeline->shouldReceive('setex')
            ->once()
            ->withArgs(function ($key, $ttl, $value) {
                $this->assertSame(1, $ttl);

                return true;
            })
            ->andReturn(true);

        $pipeline->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $redis = $this->createStore($connection);
        $redis->allTagOps()->remember()->execute('ns:foo', 0, fn () => 'bar', ['tag1:entries']);
    }

    /**
     * @test
     */
    public function testRememberUsesSequentialCommandsInClusterMode(): void
    {
        $connection = $this->mockClusterConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->with('prefix:ns:foo')
            ->andReturnNull();

        // In cluster mode, should use sequential zadd calls (not pipeline)
        $client->shouldReceive('zadd')
            ->twice()
            ->andReturn(1);

        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('value'))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->remember()->execute(
            'ns:foo',
            60,
            fn () => 'value',
            ['tag1:entries', 'tag2:entries']
        );

        $this->assertSame('value', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberWithNumericValue(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $pipeline = m::mock();
        $client->shouldReceive('pipeline')
            ->once()
            ->andReturn($pipeline);

        $pipeline->shouldReceive('zadd')
            ->once()
            ->andReturn(1);

        // Numeric values are NOT serialized
        $pipeline->shouldReceive('setex')
            ->once()
            ->withArgs(function ($key, $ttl, $value) {
                $this->assertSame(42, $value);

                return true;
            })
            ->andReturn(true);

        $pipeline->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->remember()->execute('ns:foo', 60, fn () => 42, ['tag1:entries']);

        $this->assertSame(42, $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberWithEmptyTags(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $pipeline = m::mock();
        $client->shouldReceive('pipeline')
            ->once()
            ->andReturn($pipeline);

        // No ZADD calls when tags are empty
        $pipeline->shouldReceive('zadd')->never();

        $pipeline->shouldReceive('setex')
            ->once()
            ->andReturn(true);

        $pipeline->shouldReceive('exec')
            ->once()
            ->andReturn([true]);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->remember()->execute('ns:foo', 60, fn () => 'bar', []);

        $this->assertSame('bar', $value);
        $this->assertFalse($wasHit);
    }
}
