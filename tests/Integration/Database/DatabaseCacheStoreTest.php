<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Cache\DatabaseStore;
use Hypervel\Cache\Repository;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\WithMigration;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

#[WithMigration('cache')]
class DatabaseCacheStoreTest extends DatabaseTestCase
{
    public function testValueCanStoreNewCache(): void
    {
        $store = $this->getStore();

        $store->put('foo', 'bar', 60);

        $this->assertSame('bar', $store->get('foo'));
    }

    public function testPutOperationShouldNotStoreExpired(): void
    {
        $store = $this->getStore();

        $store->put('foo', 'bar', 0);

        $this->assertDatabaseMissing($this->getCacheTableName(), ['key' => $this->withCachePrefix('foo')]);
    }

    public function testValueCanUpdateExistCache(): void
    {
        $store = $this->getStore();

        $store->put('foo', 'bar', 60);
        $store->put('foo', 'new-bar', 60);

        $this->assertSame('new-bar', $store->get('foo'));
    }

    public function testValueCanUpdateExistCacheInTransaction(): void
    {
        $store = $this->getStore();

        $store->put('foo', 'bar', 60);

        DB::beginTransaction();
        $store->put('foo', 'new-bar', 60);
        DB::commit();

        $this->assertSame('new-bar', $store->get('foo'));
    }

    public function testAddOperationShouldNotStoreExpired(): void
    {
        $store = $this->getStore();

        $result = $store->add('foo', 'bar', 0);

        $this->assertFalse($result);
        $this->assertDatabaseMissing($this->getCacheTableName(), ['key' => $this->withCachePrefix('foo')]);
    }

    public function testAddOperationCanStoreNewCache(): void
    {
        $store = $this->getStore();

        $result = $store->add('foo', 'bar', 60);

        $this->assertTrue($result);
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testAddOperationShouldNotUpdateExistCache(): void
    {
        $store = $this->getStore();

        $store->add('foo', 'bar', 60);
        $result = $store->add('foo', 'new-bar', 60);

        $this->assertFalse($result);
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testAddOperationShouldNotUpdateExistCacheInTransaction(): void
    {
        $store = $this->getStore();

        $store->add('foo', 'bar', 60);

        DB::beginTransaction();
        $result = $store->add('foo', 'new-bar', 60);
        DB::commit();

        $this->assertFalse($result);
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testAddOperationCanUpdateIfCacheExpired(): void
    {
        $store = $this->getStore();

        $this->insertToCacheTable('foo', 'bar', 0);
        $result = $store->add('foo', 'new-bar', 60);

        $this->assertTrue($result);
        $this->assertSame('new-bar', $store->get('foo'));
    }

    public function testAddOperationCanUpdateIfCacheExpiredInTransaction(): void
    {
        $store = $this->getStore();

        $this->insertToCacheTable('foo', 'bar', 0);

        DB::beginTransaction();
        $result = $store->add('foo', 'new-bar', 60);
        DB::commit();

        $this->assertTrue($result);
        $this->assertSame('new-bar', $store->get('foo'));
    }

    public function testGetOperationReturnNullIfExpired(): void
    {
        $store = $this->getStore();

        $this->insertToCacheTable('foo', 'bar', 0);

        $result = $store->get('foo');

        $this->assertNull($result);
    }

    public function testGetOperationCanDeleteExpired(): void
    {
        $store = $this->getStore();

        $this->insertToCacheTable('foo', 'bar', 0);

        $store->get('foo');

        $this->assertDatabaseMissing($this->getCacheTableName(), ['key' => $this->withCachePrefix('foo')]);
    }

    public function testForgetIfExpiredOperationCanDeleteExpired(): void
    {
        $store = $this->getStore();

        $this->insertToCacheTable('foo', 'bar', 0);

        $store->forgetIfExpired('foo');

        $this->assertDatabaseMissing($this->getCacheTableName(), ['key' => $this->withCachePrefix('foo')]);
    }

    public function testForgetIfExpiredOperationShouldNotDeleteUnExpired(): void
    {
        $store = $this->getStore();

        $store->put('foo', 'bar', 60);

        $store->forgetIfExpired('foo');

        $this->assertDatabaseHas($this->getCacheTableName(), ['key' => $this->withCachePrefix('foo')]);
    }

    public function testMany(): void
    {
        $this->insertToCacheTable('first', 'a', 60);
        $this->insertToCacheTable('second', 'b', 60);

        $store = $this->getStore();

        $this->assertEquals([
            'first' => 'a',
            'second' => 'b',
            'third' => null,
        ], $store->get(['first', 'second', 'third']));

        $this->assertEquals([
            'first' => 'a',
            'second' => 'b',
            'third' => null,
        ], $store->many(['first', 'second', 'third']));
    }

    public function testManyWithExpiredKeys(): void
    {
        $this->insertToCacheTable('first', 'a', 0);
        $this->insertToCacheTable('second', 'b', 60);

        $this->assertEquals([
            'first' => null,
            'second' => 'b',
            'third' => null,
        ], $this->getStore()->many(['first', 'second', 'third']));

        $this->assertDatabaseMissing($this->getCacheTableName(), ['key' => $this->withCachePrefix('first')]);
    }

    public function testManyAsAssociativeArray(): void
    {
        $this->insertToCacheTable('first', 'cached', 60);

        $result = $this->getStore()->many([
            'first' => 'aa',
            'second' => 'bb',
            'third',
        ]);

        $this->assertEquals([
            'first' => 'cached',
            'second' => 'bb',
            'third' => null,
        ], $result);
    }

    public function testPutMany(): void
    {
        $store = $this->getStore();

        $store->putMany($data = [
            'first' => 'a',
            'second' => 'b',
        ], 60);

        $this->assertEquals($data, $store->many(['first', 'second']));
        $this->assertDatabaseHas($this->getCacheTableName(), [
            'key' => $this->withCachePrefix('first'),
            'value' => serialize('a'),
        ]);
        $this->assertDatabaseHas($this->getCacheTableName(), [
            'key' => $this->withCachePrefix('second'),
            'value' => serialize('b'),
        ]);
    }

    public function testPutManyForeverWritesAllValuesInOneQuery(): void
    {
        $store = $this->getStore();

        $queries = $this->countQueries(fn () => $this->assertTrue($store->putMany(['first' => 'a', 'second' => 'b', 'third' => 'c'])));

        $this->assertSame(1, $queries);
        $this->assertSame(['first' => 'a', 'second' => 'b', 'third' => 'c'], $store->many(['first', 'second', 'third']));
        $this->assertGreaterThan(
            CarbonImmutable::now()->addYears(9)->getTimestamp(),
            DB::table($this->getCacheTableName())->where('key', $this->withCachePrefix('third'))->value('expiration')
        );
    }

    public function testDeleteMultipleRemovesAllKeysInOneQuery(): void
    {
        $store = $this->getStore();
        $this->insertToCacheTable('first', 'a');
        $this->insertToCacheTable('second', 'b');
        $this->insertToCacheTable('third', 'c');
        $this->insertToCacheTable(Repository::FLEXIBLE_CREATED_KEY_PREFIX . 'first', 123);

        $queries = $this->countQueries(fn () => $this->assertTrue($store->deleteMultiple(['first', 'second', 'first'])));

        $this->assertSame(1, $queries);
        $this->assertSame(['first' => null, 'second' => null, 'third' => 'c'], $store->many(['first', 'second', 'third']));
        $this->assertDatabaseMissing($this->getCacheTableName(), ['key' => $this->withCachePrefix(Repository::FLEXIBLE_CREATED_KEY_PREFIX . 'first')]);
    }

    public function testManyValuesAreWrittenAndDeletedInChunks(): void
    {
        $store = $this->getStore();
        $values = [];

        for ($i = 1; $i <= 600; ++$i) {
            $values["key-{$i}"] = "value-{$i}";
        }

        $this->assertSame(3, $this->countQueries(fn () => $this->assertTrue($store->putMany($values))));
        $this->assertSame(600, DB::table($this->getCacheTableName())->count());

        $this->assertSame(3, $this->countQueries(fn () => $this->assertTrue($store->deleteMultiple(array_keys($values)))));
        $this->assertSame(0, DB::table($this->getCacheTableName())->count());
    }

    #[TestWith([DatabaseCacheSingleKeyOverride::class])]
    #[TestWith([DatabaseCacheBulkOverride::class])]
    public function testStoreOverridesPreserveMixedPerKeyOutcomes(string $storeClass): void
    {
        $repository = new Repository(new $storeClass(app('db'), null, $this->getCacheTableName(), $this->withCachePrefix('')));

        $this->assertFalse($repository->putMany(['reserved' => 'a', 'ordinary' => 'b']));
        $this->assertSame(['reserved' => null, 'ordinary' => 'b'], $repository->many(['reserved', 'ordinary']));

        $this->insertToCacheTable('reserved', 'original');
        $this->assertFalse($repository->deleteMultiple(['reserved', 'ordinary']));
        $this->assertSame(['reserved' => 'original', 'ordinary' => null], $repository->many(['reserved', 'ordinary']));
    }

    public function testResolvingSQLiteConnectionDoesNotThrowExceptions(): void
    {
        $originalConfiguration = config('database');

        app('config')->set('database.default', 'sqlite');
        app('config')->set('database.connections.sqlite.database', __DIR__ . '/non-existing-file');

        $store = $this->getStore();
        $this->assertInstanceOf(SQLiteConnection::class, $store->getConnection());

        app('config')->set('database', $originalConfiguration);
    }

    public function testLocksCanBeFlushed(): void
    {
        $store = $this->getStore();

        $store->lock('lock-1', 60)->acquire();
        $store->lock('lock-2', 60)->acquire();
        $store->lock('lock-3', 60)->acquire();

        $this->assertTrue($store->flushLocks());

        $this->assertTrue($store->lock('lock-1', 60)->acquire());
        $this->assertTrue($store->lock('lock-2', 60)->acquire());
        $this->assertTrue($store->lock('lock-3', 60)->acquire());
    }

    public function testFlushLocksDoesNotAffectCacheEntries(): void
    {
        $store = $this->getStore();

        $store->put('foo', 'bar', 60);
        $store->lock('lock-1', 60)->acquire();

        $store->flushLocks();

        $this->assertSame('bar', $store->get('foo'));
        $this->assertDatabaseHas($this->getCacheTableName(), ['key' => $this->withCachePrefix('foo')]);
    }

    public function testFlushLocksRemovesExpiredLocksToo(): void
    {
        $store = $this->getStore();

        $this->insertToLocksTable('stale-lock', 'owner', 0);
        $store->lock('active-lock', 60)->acquire();

        $store->flushLocks();

        $this->assertTrue($store->lock('active-lock', 60)->acquire());
        $this->assertDatabaseMissing($this->getLocksTableName(), ['key' => $this->withCachePrefix('stale-lock')]);
    }

    public function testHasSeparateLockStoreReturnsTrueWhenTablesAreDifferent(): void
    {
        $store = new DatabaseStore(
            resolver: app('db'),
            connectionName: null,
            table: $this->getCacheTableName(),
            lockTable: $this->getLocksTableName(),
        );

        $this->assertTrue($store->hasSeparateLockStore());
    }

    public function testHasSeparateLockStoreReturnsFalseWhenTablesAreTheSame(): void
    {
        $store = new DatabaseStore(
            resolver: app('db'),
            connectionName: null,
            table: $this->getCacheTableName(),
            lockTable: $this->getCacheTableName(),
        );

        $this->assertFalse($store->hasSeparateLockStore());
    }

    public function testFlushLocksThrowsExceptionWhenTablesAreTheSame(): void
    {
        $store = new DatabaseStore(
            resolver: app('db'),
            connectionName: null,
            table: $this->getCacheTableName(),
            lockTable: $this->getCacheTableName(),
        );

        $this->expectException(RuntimeException::class);

        $store->flushLocks();
    }

    protected function getStore(): Repository
    {
        return Cache::store('database');
    }

    /**
     * Count queries on the database cache store's connection.
     */
    protected function countQueries(callable $callback): int
    {
        $connection = $this->getStore()->getStore()->getConnection();

        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $callback();

            return count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }
    }

    protected function getCacheTableName(): string
    {
        return config('cache.stores.database.table');
    }

    protected function getLocksTableName(): string
    {
        return config('cache.stores.database.lock_table') ?: 'cache_locks';
    }

    protected function withCachePrefix(string $key): string
    {
        return config('cache.prefix') . $key;
    }

    protected function insertToCacheTable(string $key, mixed $value, int $ttl = 60): void
    {
        DB::table($this->getCacheTableName())
            ->insert(
                [
                    'key' => $this->withCachePrefix($key),
                    'value' => serialize($value),
                    'expiration' => CarbonImmutable::now()->addSeconds($ttl)->getTimestamp(),
                ]
            );
    }

    protected function insertToLocksTable(string $key, string $owner, int $ttl = 60): void
    {
        DB::table($this->getLocksTableName())
            ->insert(
                [
                    'key' => $this->withCachePrefix($key),
                    'owner' => $owner,
                    'expiration' => CarbonImmutable::now()->addSeconds($ttl)->getTimestamp(),
                ]
            );
    }
}

class DatabaseCacheSingleKeyOverride extends DatabaseStore
{
    /**
     * Store an item unless its key is reserved.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return $key !== 'reserved' && parent::put($key, $value, $seconds);
    }

    /**
     * Remove an item unless its key is reserved.
     */
    public function forget(string $key): bool
    {
        return $key !== 'reserved' && parent::forget($key);
    }
}

class DatabaseCacheBulkOverride extends DatabaseStore
{
    /**
     * Store the items unless the batch includes a reserved key.
     */
    public function putMany(array $values, int $seconds): bool
    {
        return ! array_key_exists('reserved', $values) && parent::putMany($values, $seconds);
    }

    /**
     * Remove the items unless the batch includes a reserved key.
     */
    protected function forgetMany(array $keys): bool
    {
        return ! in_array('reserved', $keys, true) && parent::forgetMany($keys);
    }
}
