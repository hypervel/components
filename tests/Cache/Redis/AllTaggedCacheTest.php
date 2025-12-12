<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use Carbon\Carbon;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis;

/**
 * Tests for AllTaggedCache behavior.
 *
 * These tests verify the high-level API behavior of tagged cache operations.
 * For detailed operation tests, see tests/Cache/Redis/Operations/AllTag/.
 *
 * @internal
 * @coversNothing
 */
class AllTaggedCacheTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testTagEntriesCanBeStoredForever(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $key = sha1('_all:tag:people:entries|_all:tag:author:entries') . ':name';

        // Combined operation: ZADD for both tags + SET (forever uses score -1)
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:people:entries', -1, $key)->andReturn($client);
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:author:entries', -1, $key)->andReturn($client);
        $client->shouldReceive('set')->once()->with("prefix:{$key}", serialize('Sally'))->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, 1, true]);

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
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $key = sha1('_all:tag:people:entries|_all:tag:author:entries') . ':age';

        // Numeric values are NOT serialized (optimization)
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:people:entries', -1, $key)->andReturn($client);
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:author:entries', -1, $key)->andReturn($client);
        $client->shouldReceive('set')->once()->with("prefix:{$key}", 30)->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, 1, true]);

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
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $key = sha1('_all:tag:votes:entries') . ':person-1';

        // Combined operation: ZADD NX + INCRBY in single pipeline
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:votes:entries', ['NX'], -1, $key)->andReturn($client);
        $client->shouldReceive('incrby')->once()->with("prefix:{$key}", 1)->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, 1]);

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
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $key = sha1('_all:tag:votes:entries') . ':person-1';

        // Combined operation: ZADD NX + DECRBY in single pipeline
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:votes:entries', ['NX'], -1, $key)->andReturn($client);
        $client->shouldReceive('decrby')->once()->with("prefix:{$key}", 1)->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, 9]);

        $store = $this->createStore($connection);
        $result = $store->tags(['votes'])->decrement('person-1');

        $this->assertSame(9, $result);
    }

    /**
     * @test
     */
    public function testStaleEntriesCanBeFlushed(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // FlushStaleEntries uses pipeline for zRemRangeByScore
        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:people:entries', '0', (string) now()->timestamp)
            ->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([0]);

        $store = $this->createStore($connection);
        $store->tags(['people'])->flushStale();
    }

    /**
     * @test
     */
    public function testPut(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $key = sha1('_all:tag:people:entries|_all:tag:author:entries') . ':name';
        $expectedScore = now()->timestamp + 5;

        // Combined operation: ZADD for both tags + SETEX in single pipeline
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:people:entries', $expectedScore, $key)->andReturn($client);
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:author:entries', $expectedScore, $key)->andReturn($client);
        $client->shouldReceive('setex')->once()->with("prefix:{$key}", 5, serialize('Sally'))->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->put('name', 'Sally', 5);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithNumericValue(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $key = sha1('_all:tag:people:entries|_all:tag:author:entries') . ':age';
        $expectedScore = now()->timestamp + 5;

        // Numeric values are NOT serialized
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:people:entries', $expectedScore, $key)->andReturn($client);
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:author:entries', $expectedScore, $key)->andReturn($client);
        $client->shouldReceive('setex')->once()->with("prefix:{$key}", 5, 30)->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['people', 'author'])->put('age', 30, 5);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithArray(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $namespace = sha1('_all:tag:people:entries|_all:tag:author:entries') . ':';
        $expectedScore = now()->timestamp + 5;

        // PutMany uses variadic ZADD: one command per tag with all keys as members
        // First tag (people) gets both keys in one ZADD
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:people:entries', $expectedScore, $namespace . 'name', $expectedScore, $namespace . 'age')
            ->andReturn($client);

        // Second tag (author) gets both keys in one ZADD
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:author:entries', $expectedScore, $namespace . 'name', $expectedScore, $namespace . 'age')
            ->andReturn($client);

        // SETEX for each key
        $client->shouldReceive('setex')->once()->with("prefix:{$namespace}name", 5, serialize('Sally'))->andReturn($client);
        $client->shouldReceive('setex')->once()->with("prefix:{$namespace}age", 5, 30)->andReturn($client);

        // Results: 2 ZADDs + 2 SETEXs
        $client->shouldReceive('exec')->once()->andReturn([2, 2, true, true]);

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
        $client = $connection->_mockClient;

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

        // Delete cache entries (via pipeline on client)
        $client->shouldReceive('del')
            ->once()
            ->with('prefix:key1', 'prefix:key2')
            ->andReturn(2);

        // Delete tag set (on connection, not client)
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
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $key = sha1('_all:tag:users:entries') . ':name';

        // Null TTL should call forever (ZADD with -1 + SET)
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', -1, $key)->andReturn($client);
        $client->shouldReceive('set')->once()->with("prefix:{$key}", serialize('John'))->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, true]);

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
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $key = sha1('_all:tag:counters:entries') . ':hits';

        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:counters:entries', ['NX'], -1, $key)->andReturn($client);
        $client->shouldReceive('incrby')->once()->with("prefix:{$key}", 5)->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, 15]);

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
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $key = sha1('_all:tag:counters:entries') . ':stock';

        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:counters:entries', ['NX'], -1, $key)->andReturn($client);
        $client->shouldReceive('decrby')->once()->with("prefix:{$key}", 3)->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([0, 7]);

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
        $client = $connection->_mockClient;

        $key = sha1('_all:tag:users:entries') . ':profile';

        // Remember operation uses client->get() directly
        $client->shouldReceive('get')
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
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $key = sha1('_all:tag:users:entries') . ':profile';
        $expectedScore = now()->timestamp + 60;

        // Cache miss
        $client->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        // Pipeline for ZADD + SETEX on miss
        $client->shouldReceive('pipeline')->once()->andReturn($client);
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', $expectedScore, $key)->andReturn($client);
        $client->shouldReceive('setex')->once()->with("prefix:{$key}", 60, serialize('computed_value'))->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, true]);

        $callCount = 0;
        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->remember('profile', 60, function () use (&$callCount) {
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
        $client = $connection->_mockClient;

        $key = sha1('_all:tag:users:entries') . ':data';

        $client->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturn(serialize('existing_value'));

        $callCount = 0;
        $store = $this->createStore($connection);
        $result = $store->tags(['users'])->remember('data', 60, function () use (&$callCount) {
            $callCount++;

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
        $client = $connection->_mockClient;

        $key = sha1('_all:tag:config:entries') . ':settings';

        $client->shouldReceive('get')
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
        $client = $connection->_mockClient;

        $key = sha1('_all:tag:config:entries') . ':settings';

        // Cache miss
        $client->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        // Pipeline for ZADD (score -1) + SET on miss
        $client->shouldReceive('pipeline')->once()->andReturn($client);
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:config:entries', -1, $key)->andReturn($client);
        $client->shouldReceive('set')->once()->with("prefix:{$key}", serialize('computed_settings'))->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, true]);

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
        $client = $connection->_mockClient;

        $key = sha1('_all:tag:users:entries') . ':data';

        $client->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $store = $this->createStore($connection);
        $store->tags(['users'])->remember('data', 60, function () {
            throw new \RuntimeException('Callback failed');
        });
    }

    /**
     * @test
     */
    public function testRememberForeverPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $key = sha1('_all:tag:config:entries') . ':data';

        $client->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Forever callback failed');

        $store = $this->createStore($connection);
        $store->tags(['config'])->rememberForever('data', function () {
            throw new \RuntimeException('Forever callback failed');
        });
    }

    /**
     * @test
     */
    public function testRememberWithMultipleTags(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $key = sha1('_all:tag:users:entries|_all:tag:posts:entries') . ':activity';
        $expectedScore = now()->timestamp + 120;

        // Cache miss
        $client->shouldReceive('get')
            ->once()
            ->with("prefix:{$key}")
            ->andReturnNull();

        // Pipeline for ZADDs + SETEX on miss
        $client->shouldReceive('pipeline')->once()->andReturn($client);
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:users:entries', $expectedScore, $key)->andReturn($client);
        $client->shouldReceive('zadd')->once()->with('prefix:_all:tag:posts:entries', $expectedScore, $key)->andReturn($client);
        $client->shouldReceive('setex')->once()->with("prefix:{$key}", 120, serialize('activity_data'))->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->tags(['users', 'posts'])->remember('activity', 120, fn () => 'activity_data');

        $this->assertSame('activity_data', $result);
    }
}
