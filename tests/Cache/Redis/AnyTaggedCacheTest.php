<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use BadMethodCallException;
use Generator;
use Hypervel\Cache\Redis\AnyTaggedCache;
use Hypervel\Cache\Redis\AnyTagSet;
use Hypervel\Cache\TaggedCache;
use Hypervel\Redis\Exceptions\LuaScriptException;
use RuntimeException;

/**
 * Tests for AnyTaggedCache behavior.
 *
 * These tests verify the high-level API behavior of union-mode tagged cache operations.
 * For detailed operation tests, see tests/Cache/Redis/Operations/AnyTag/.
 *
 * @internal
 * @coversNothing
 */
class AnyTaggedCacheTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testIsInstanceOfTaggedCache(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->assertInstanceOf(TaggedCache::class, $cache);
        $this->assertInstanceOf(AnyTaggedCache::class, $cache);
    }

    /**
     * @test
     */
    public function testGetThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot get items via tags in any mode');

        $cache->get('key');
    }

    /**
     * @test
     */
    public function testManyThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot get items via tags in any mode');

        $cache->many(['key1', 'key2']);
    }

    /**
     * @test
     */
    public function testHasThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot check existence via tags in any mode');

        $cache->has('key');
    }

    /**
     * @test
     */
    public function testPullThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot pull items via tags in any mode');

        $cache->pull('key');
    }

    /**
     * @test
     */
    public function testForgetThrowsBadMethodCallException(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot forget items via tags in any mode');

        $cache->forget('key');
    }

    /**
     * @test
     */
    public function testPutStoresValueWithTags(): void
    {
        $connection = $this->mockConnection();

        // Union mode uses Lua script via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users', 'posts'])->put('mykey', 'myvalue', 60);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithNullTtlCallsForever(): void
    {
        $connection = $this->mockConnection();

        // Forever operation uses Lua script via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users', 'posts'])->put('mykey', 'myvalue', null);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithZeroTtlReturnsFalse(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $result = $cache->put('mykey', 'myvalue', 0);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testPutWithArrayCallsPutMany(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // PutMany uses pipeline with Lua operations
        $client->shouldReceive('pipeline')->andReturn($client);
        $client->shouldReceive('smembers')->andReturn($client);
        $client->shouldReceive('exec')->andReturn([[], []]);
        $client->shouldReceive('setex')->andReturn($client);
        $client->shouldReceive('del')->andReturn($client);
        $client->shouldReceive('sadd')->andReturn($client);
        $client->shouldReceive('expire')->andReturn($client);
        $client->shouldReceive('hSet')->andReturn($client);
        $client->shouldReceive('hexpire')->andReturn($client);
        $client->shouldReceive('zadd')->andReturn($client);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->put(['key1' => 'value1', 'key2' => 'value2'], 60);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyStoresMultipleValues(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // PutMany uses pipeline
        $client->shouldReceive('pipeline')->andReturn($client);
        $client->shouldReceive('smembers')->andReturn($client);
        $client->shouldReceive('exec')->andReturn([[], []]);
        $client->shouldReceive('setex')->andReturn($client);
        $client->shouldReceive('del')->andReturn($client);
        $client->shouldReceive('sadd')->andReturn($client);
        $client->shouldReceive('expire')->andReturn($client);
        $client->shouldReceive('hSet')->andReturn($client);
        $client->shouldReceive('hexpire')->andReturn($client);
        $client->shouldReceive('zadd')->andReturn($client);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->putMany(['key1' => 'value1', 'key2' => 'value2'], 120);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyWithNullTtlCallsForeverForEach(): void
    {
        $connection = $this->mockConnection();

        // Forever for each key - called twice for 2 keys
        $connection->shouldReceive('evalWithShaCache')
            ->twice()
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->putMany(['key1' => 'value1', 'key2' => 'value2'], null);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyWithZeroTtlReturnsFalse(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users']);

        $result = $cache->putMany(['key1' => 'value1'], 0);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testAddStoresValueIfNotExists(): void
    {
        $connection = $this->mockConnection();

        // Add uses Lua script with SET NX via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->add('mykey', 'myvalue', 60);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddWithNullTtlDefaultsToOneYear(): void
    {
        $connection = $this->mockConnection();

        // Add with null TTL defaults to 1 year (31536000 seconds)
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                // Check that TTL argument is ~1 year (args[1] is ttl)
                $this->assertSame(31536000, $args[1]);

                return true;
            })
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->add('mykey', 'myvalue', null);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddWithZeroTtlReturnsFalse(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users']);

        $result = $cache->add('mykey', 'myvalue', 0);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testForeverStoresValueIndefinitely(): void
    {
        $connection = $this->mockConnection();

        // Forever uses Lua script without expiration via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->forever('mykey', 'myvalue');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testIncrementReturnsNewValue(): void
    {
        $connection = $this->mockConnection();

        // Increment uses Lua script with INCRBY via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(5);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->increment('counter');

        $this->assertSame(5, $result);
    }

    /**
     * @test
     */
    public function testIncrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(15);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->increment('counter', 10);

        $this->assertSame(15, $result);
    }

    /**
     * @test
     */
    public function testDecrementReturnsNewValue(): void
    {
        $connection = $this->mockConnection();

        // Decrement uses Lua script with DECRBY via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(3);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->decrement('counter');

        $this->assertSame(3, $result);
    }

    /**
     * @test
     */
    public function testDecrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(0);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->decrement('counter', 5);

        $this->assertSame(0, $result);
    }

    /**
     * @test
     */
    public function testFlushDeletesAllTaggedItems(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // GetTaggedKeys uses hlen to check size
        // When small (< threshold), it uses hkeys directly instead of scan
        $client->shouldReceive('hlen')
            ->andReturn(2);
        $client->shouldReceive('hkeys')
            ->once()
            ->andReturn(['key1', 'key2']);

        // After getting keys, Flush uses pipeline for delete operations
        $client->shouldReceive('pipeline')->andReturn($client);
        $client->shouldReceive('del')->andReturn($client);
        $client->shouldReceive('unlink')->andReturn($client);
        $client->shouldReceive('zrem')->andReturn($client);
        $client->shouldReceive('exec')->andReturn([2, 1]);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->flush();

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testRememberRetrievesExistingValueFromStore(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // The Remember operation calls $client->get() directly
        $client->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturn(serialize('cached_value'));

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, fn () => 'new_value');

        $this->assertSame('cached_value', $result);
    }

    /**
     * @test
     */
    public function testRememberCallsCallbackAndStoresValueWhenMiss(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Client returns null (miss) - Remember operation uses client->get() directly
        $client->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturnNull();

        // Should store the value with tags via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $callCount = 0;
        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, function () use (&$callCount) {
            ++$callCount;

            return 'computed_value';
        });

        $this->assertSame('computed_value', $result);
        $this->assertSame(1, $callCount);
    }

    /**
     * @test
     */
    public function testRememberForeverRetrievesExistingValueFromStore(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // RememberForever operation uses $client->get() directly
        $client->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturn(serialize('cached_value'));

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberForever('mykey', fn () => 'new_value');

        $this->assertSame('cached_value', $result);
    }

    /**
     * @test
     */
    public function testRememberForeverCallsCallbackAndStoresValueWhenMiss(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // RememberForever operation uses $client->get() directly - returns null (miss)
        $client->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturnNull();

        // Should store the value forever with tags using evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->rememberForever('mykey', fn () => 'computed_value');

        $this->assertSame('computed_value', $result);
    }

    /**
     * @test
     */
    public function testGetTagsReturnsTagSet(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $cache = $store->setTagMode('any')->tags(['users', 'posts']);

        $this->assertInstanceOf(AnyTagSet::class, $cache->getTags());
    }

    /**
     * @test
     */
    public function testItemKeyReturnsKeyUnchanged(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // In any mode, keys are NOT namespaced by tags
        $client->shouldReceive('get')
            ->once()
            ->with('prefix:mykey') // Should NOT have tag namespace prefix
            ->andReturn(serialize('value'));

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, fn () => 'fallback');
    }

    /**
     * @test
     */
    public function testIncrementThrowsOnLuaFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andThrow(new LuaScriptException('Lua script execution failed'));

        $this->expectException(LuaScriptException::class);

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->increment('counter');
    }

    /**
     * @test
     */
    public function testDecrementThrowsOnLuaFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andThrow(new LuaScriptException('Lua script execution failed'));

        $this->expectException(LuaScriptException::class);

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->decrement('counter');
    }

    /**
     * @test
     */
    public function testRememberPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Client returns null (cache miss) - callback will be executed
        $client->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, function () {
            throw new RuntimeException('Callback failed');
        });
    }

    /**
     * @test
     */
    public function testRememberForeverPropagatesExceptionFromCallback(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Client returns null (cache miss) - callback will be executed
        $client->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forever callback failed');

        $store = $this->createStore($connection);
        $store->setTagMode('any')->tags(['users'])->rememberForever('mykey', function () {
            throw new RuntimeException('Forever callback failed');
        });
    }

    /**
     * @test
     */
    public function testRememberDoesNotCallCallbackWhenValueExists(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Client returns existing value (cache hit)
        $client->shouldReceive('get')
            ->once()
            ->with('prefix:mykey')
            ->andReturn(serialize('cached_value'));

        $callCount = 0;
        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->remember('mykey', 60, function () use (&$callCount) {
            ++$callCount;

            return 'new_value';
        });

        $this->assertSame('cached_value', $result);
        $this->assertSame(0, $callCount, 'Callback should not be called when cache hit');
    }

    /**
     * @test
     */
    public function testItemsReturnsGenerator(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // GetTaggedKeys uses hlen to check size first
        $client->shouldReceive('hlen')
            ->andReturn(2);

        // When small (< threshold), it uses hkeys directly
        $client->shouldReceive('hkeys')
            ->once()
            ->andReturn(['key1', 'key2']);

        // Get values for found keys (mget receives array)
        $client->shouldReceive('mget')
            ->once()
            ->with(['prefix:key1', 'prefix:key2'])
            ->andReturn([serialize('value1'), serialize('value2')]);

        $store = $this->createStore($connection);
        $result = $store->setTagMode('any')->tags(['users'])->items();

        $this->assertInstanceOf(Generator::class, $result);

        // Iterate the generator to verify it works and trigger the Redis calls
        $items = iterator_to_array($result);
        $this->assertCount(2, $items);
    }
}
