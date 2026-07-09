<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Cache\DatabaseStore;
use Hypervel\Cache\Repository;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\WithMigration;
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
                    'expiration' => Carbon::now()->addSeconds($ttl)->getTimestamp(),
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
                    'expiration' => Carbon::now()->addSeconds($ttl)->getTimestamp(),
                ]
            );
    }
}
