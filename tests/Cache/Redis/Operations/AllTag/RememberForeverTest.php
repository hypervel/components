<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

/**
 * Tests for the AllTag RememberForever operation.
 *
 * Tests the single-connection optimization that performs GET and conditional
 * tagged SET (ZADD with score -1 + SET without TTL) in a single pool checkout.
 *
 * Key difference from Remember: uses score -1 for forever items (prevents
 * cleanup by ZREMRANGEBYSCORE) and SET without TTL instead of SETEX.
 *
 * @internal
 * @coversNothing
 */
class RememberForeverTest extends TestCase
{
    use MocksRedisConnections;

    private const FOREVER_SCORE = -1;

    /**
     * @test
     */
    public function testRememberForeverReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->with('prefix:ns:foo')
            ->andReturn(serialize('cached_value'));

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->rememberForever()->execute('ns:foo', fn () => 'new_value', ['tag1:entries']);

        $this->assertSame('cached_value', $value);
        $this->assertTrue($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverCallsCallbackOnCacheMissUsingPipeline(): void
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

        // ZADD for each tag with score -1 (forever marker)
        $pipeline->shouldReceive('zadd')
            ->once()
            ->withArgs(function ($key, $score, $member) {
                $this->assertSame('prefix:tag1:entries', $key);
                $this->assertSame(self::FOREVER_SCORE, $score);
                $this->assertSame('ns:foo', $member);

                return true;
            });

        // SET (not SETEX) for forever items
        $pipeline->shouldReceive('set')
            ->once()
            ->withArgs(function ($key, $value) {
                $this->assertSame('prefix:ns:foo', $key);
                $this->assertSame(serialize('computed_value'), $value);

                return true;
            });

        $pipeline->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $callCount = 0;
        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->rememberForever()->execute('ns:foo', function () use (&$callCount) {
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
    public function testRememberForeverDoesNotCallCallbackOnCacheHit(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->with('prefix:ns:foo')
            ->andReturn(serialize('existing_value'));

        $callCount = 0;
        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->rememberForever()->execute('ns:foo', function () use (&$callCount) {
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
    public function testRememberForeverWithMultipleTags(): void
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

        // Should ZADD to each tag's sorted set with score -1
        $pipeline->shouldReceive('zadd')
            ->times(3)
            ->withArgs(function ($key, $score, $member) {
                $this->assertSame(self::FOREVER_SCORE, $score);

                return true;
            })
            ->andReturn(1);

        $pipeline->shouldReceive('set')
            ->once()
            ->andReturn(true);

        $pipeline->shouldReceive('exec')
            ->once()
            ->andReturn([1, 1, 1, true]);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->rememberForever()->execute(
            'ns:foo',
            fn () => 'value',
            ['tag1:entries', 'tag2:entries', 'tag3:entries']
        );

        $this->assertSame('value', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('get')
            ->once()
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $redis = $this->createStore($connection);
        $redis->allTagOps()->rememberForever()->execute('ns:foo', function () {
            throw new RuntimeException('Callback failed');
        }, ['tag1:entries']);
    }

    /**
     * @test
     */
    public function testRememberForeverUsesSequentialCommandsInClusterMode(): void
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
            ->withArgs(function ($key, $score, $member) {
                // Score may be float or int depending on implementation
                $this->assertEquals(self::FOREVER_SCORE, $score);

                return true;
            })
            ->andReturn(1);

        // SET without TTL
        $client->shouldReceive('set')
            ->once()
            ->with('prefix:ns:foo', serialize('value'))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->rememberForever()->execute(
            'ns:foo',
            fn () => 'value',
            ['tag1:entries', 'tag2:entries']
        );

        $this->assertSame('value', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverWithNumericValue(): void
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
        $pipeline->shouldReceive('set')
            ->once()
            ->withArgs(function ($key, $value) {
                $this->assertSame(42, $value);

                return true;
            })
            ->andReturn(true);

        $pipeline->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->rememberForever()->execute('ns:foo', fn () => 42, ['tag1:entries']);

        $this->assertSame(42, $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverWithEmptyTags(): void
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

        $pipeline->shouldReceive('set')
            ->once()
            ->andReturn(true);

        $pipeline->shouldReceive('exec')
            ->once()
            ->andReturn([true]);

        $redis = $this->createStore($connection);
        [$value, $wasHit] = $redis->allTagOps()->rememberForever()->execute('ns:foo', fn () => 'bar', []);

        $this->assertSame('bar', $value);
        $this->assertFalse($wasHit);
    }

    /**
     * @test
     */
    public function testRememberForeverUsesNegativeOneScoreForForeverMarker(): void
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

        // Verify score is -1 (the "forever" marker that prevents cleanup)
        $capturedScore = null;
        $pipeline->shouldReceive('zadd')
            ->once()
            ->withArgs(function ($key, $score, $member) use (&$capturedScore) {
                $capturedScore = $score;

                return true;
            })
            ->andReturn(1);

        $pipeline->shouldReceive('set')
            ->once()
            ->andReturn(true);

        $pipeline->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $redis = $this->createStore($connection);
        $redis->allTagOps()->rememberForever()->execute('ns:foo', fn () => 'bar', ['tag1:entries']);

        $this->assertSame(-1, $capturedScore, 'Forever items should use score -1');
    }
}
