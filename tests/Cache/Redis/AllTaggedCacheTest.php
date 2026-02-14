<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use RuntimeException;

/**
 * Tests for AllTaggedCache behavior.
 *
 * These tests verify the high-level API behavior of tagged cache operations.
 * For detailed operation tests, see tests/Cache/Redis/Operations/AllTag/.
 *
 * @internal
 * @coversNothing
 */
class AllTaggedCacheTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testTagEntriesCanBeStoredForever(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = sha1('_all:tag:people:entries|_all:tag:author:entries') . ':name';

        // Combined operation: ZADD for both tags + SET (forever uses score -1)
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:people:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:author:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('set')->once()->with("prefix:{$key}", serialize('Sally'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->forever('name', 'Sally');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testTagEntriesCanBeStoredForeverWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = sha1('_all:tag:people:entries|_all:tag:author:entries') . ':age';

        // Numeric values are NOT serialized (optimization)
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:people:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:author:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('set')->once()->with("prefix:{$key}", 30)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->forever('age', 30);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testTagEntriesCanBeIncremented(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = sha1('_all:tag:votes:entries') . ':person-1';

        // Combined operation: ZADD NX + INCRBY in single pipeline
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:votes:entries', ['NX'], -1, $key)->andReturn($connection);
        $connection->shouldReceive('incrby')->once()->with("prefix:{$key}", 1)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 1]);

        $store = $this->createStore($connection);
        $result = $store->tags(['votes'])->increment('person-1');

        $this->assertSame(1, $result);
    }

    /**
     * @test
     */
    public function testTagEntriesCanBeDecremented(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = sha1('_all:tag:votes:entries') . ':person-1';

        // Combined operation: ZADD NX + DECRBY in single pipeline
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:votes:entries', ['NX'], -1, $key)->andReturn($connection);
        $connection->shouldReceive('decrby')->once()->with("prefix:{$key}", 1)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 9]);

        $store = $this->createStore($connection);
        $result = $store->tags(['votes'])->decrement('person-1');

        $this->assertSame(9, $result);
    }

    /**
     * @test
     */
    public function testStaleEntriesCanBeFlushed(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // FlushStaleEntries uses pipeline for zRemRangeByScore
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:people:entries', '0', (string) now()->timestamp)
            ->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([0]);

        $store = $this->createStore($connection);
        $store->tags(['people'])->flushStale();
    }

    /**
     * @test
     */
    public function testPut(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = sha1('_all:tag:people:entries|_all:tag:author:entries') . ':name';
        $expectedScore = now()->timestamp + 5;

        // Combined operation: ZADD for both tags + SETEX in single pipeline
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:people:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:author:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('setex')->once()->with("prefix:{$key}", 5, serialize('Sally'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->put('name', 'Sally', 5);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = sha1('_all:tag:people:entries|_all:tag:author:entries') . ':age';
        $expectedScore = now()->timestamp + 5;

        // Numeric values are NOT serialized
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:people:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:author:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('setex')->once()->with("prefix:{$key}", 5, 30)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->put('age', 30, 5);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithArray(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $namespace = sha1('_all:tag:people:entries|_all:tag:author:entries') . ':';
        $expectedScore = now()->timestamp + 5;

        // PutMany uses variadic ZADD: one command per tag with all keys as members
        // First tag (people) gets both keys in one ZADD
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:people:entries', $expectedScore, $namespace . 'name', $expectedScore, $namespace . 'age')
            ->andReturn($connection);

        // Second tag (author) gets both keys in one ZADD
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:author:entries', $expectedScore, $namespace . 'name', $expectedScore, $namespace . 'age')
            ->andReturn($connection);

        // SETEX for each key
        $connection->shouldReceive('setex')->once()->with("prefix:{$namespace}name", 5, serialize('Sally'))->andReturn($connection);
        $connection->shouldReceive('setex')->once()->with("prefix:{$namespace}age", 5, 30)->andReturn($connection);

        // Results: 2 ZADDs + 2 SETEXs
        $connection->shouldReceive('exec')->once()->andReturn([2, 2, true, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->put([
            'name' => 'Sally',
            'age' => 30,
        ], 5);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testFlush(): void
    {
        $connection = $this->mockConnection();

        // Flush operation scans tag sets and deletes entries
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:people:entries', null, '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return ['key1' => 0, 'key2' => 0];
            });
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:people:entries', 0, '*', 1000)
            ->andReturnNull();

        // Delete cache entries
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:key1', 'prefix:key2')
            ->andReturn(2);

        // Delete tag set
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:people:entries')
            ->andReturn(1);

        $store = $this->createStore($connection);
        $result = $store->tags(['people'])->flush();

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutNullTtlCallsForever(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = sha1('_all:tag:users:entries') . ':name';

        // Null TTL should call forever (ZADD with -1 + SET)
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('set')->once()->with("prefix:{$key}", serialize('John'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->put('name', 'John', null);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutZeroTtlDeletesKey(): void
    {
        $connection = $this->mockConnection();

        $key = sha1('_all:tag:users:entries') . ':name';

        // Zero TTL should delete the key (Forget operation uses connection)
        $connection->shouldReceive('del')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(1);

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->put('name', 'John', 0);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testIncrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = sha1('_all:tag:counters:entries') . ':hits';

        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:counters:entries', ['NX'], -1, $key)->andReturn($connection);
        $connection->shouldReceive('incrby')->once()->with("prefix:{$key}", 5)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 15]);

        $store = $this->createStore($connection);
        $result = $store->tags(['counters'])->increment('hits', 5);

        $this->assertSame(15, $result);
    }

    /**
     * @test
     */
    public function testDecrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $key = sha1('_all:tag:counters:entries') . ':stock';

        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:counters:entries', ['NX'], -1, $key)->andReturn($connection);
        $connection->shouldReceive('decrby')->once()->with("prefix:{$key}", 3)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([0, 7]);

        $store = $this->createStore($connection);
        $result = $store->tags(['counters'])->decrement('stock', 3);

        $this->assertSame(7, $result);
    }

    /**
     * @test
     */
    public function testRememberReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $key = sha1('_all:tag:users:entries') . ':profile';

        // Remember operation uses connection->get() directly
        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize('cached_value'));

        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->remember('profile', 60, fn () => 'new_value');

        $this->assertSame('cached_value', $result);
    }

    /**
     * @test
     */
    public function testRememberCallsCallbackAndStoresValueOnMiss(): void
    {
        $connection = $this->mockConnection();

        $key = sha1('_all:tag:users:entries') . ':profile';
        $expectedScore = now()->timestamp + 60;

        // Cache miss
        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        // Pipeline for ZADD + SETEX on miss
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('setex')->once()->with("prefix:{$key}", 60, serialize('computed_value'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $callCount = 0;
        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->remember('profile', 60, function () use (&$callCount) {
            ++$callCount;

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

        $key = sha1('_all:tag:users:entries') . ':data';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize('existing_value'));

        $callCount = 0;
        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->remember('data', 60, function () use (&$callCount) {
            ++$callCount;

            return 'new_value';
        });

        $this->assertSame('existing_value', $result);
        $this->assertSame(0, $callCount, 'Callback should not be called on cache hit');
    }

    /**
     * @test
     */
    public function testRememberForeverReturnsExistingValueOnCacheHit(): void
    {
        $connection = $this->mockConnection();

        $key = sha1('_all:tag:config:entries') . ':settings';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize('cached_settings'));

        $store = $this->createStore($connection);
        $result = $store->tags(['config'])->rememberForever('settings', fn () => 'new_settings');

        $this->assertSame('cached_settings', $result);
    }

    /**
     * @test
     */
    public function testRememberForeverCallsCallbackAndStoresValueOnMiss(): void
    {
        $connection = $this->mockConnection();

        $key = sha1('_all:tag:config:entries') . ':settings';

        // Cache miss
        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        // Pipeline for ZADD (score -1) + SET on miss
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:config:entries', -1, $key)->andReturn($connection);
        $connection->shouldReceive('set')->once()->with("prefix:{$key}", serialize('computed_settings'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['config'])->rememberForever('settings', fn () => 'computed_settings');

        $this->assertSame('computed_settings', $result);
    }

    /**
     * @test
     */
    public function testRememberPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        $key = sha1('_all:tag:users:entries') . ':data';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $store = $this->createStore($connection);
        $store->tags(['users'])->remember('data', 60, function () {
            throw new RuntimeException('Callback failed');
        });
    }

    /**
     * @test
     */
    public function testRememberForeverPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();

        $key = sha1('_all:tag:config:entries') . ':data';

        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forever callback failed');

        $store = $this->createStore($connection);
        $store->tags(['config'])->rememberForever('data', function () {
            throw new RuntimeException('Forever callback failed');
        });
    }

    /**
     * @test
     */
    public function testRememberWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $key = sha1('_all:tag:users:entries|_all:tag:posts:entries') . ':activity';
        $expectedScore = now()->timestamp + 120;

        // Cache miss
        $connection->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        // Pipeline for ZADDs + SETEX on miss
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->with('prefix:_all:tag:posts:entries', $expectedScore, $key)->andReturn($connection);
        $connection->shouldReceive('setex')->once()->with("prefix:{$key}", 120, serialize('activity_data'))->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['users', 'posts'])->remember('activity', 120, fn () => 'activity_data');

        $this->assertSame('activity_data', $result);
    }
}
