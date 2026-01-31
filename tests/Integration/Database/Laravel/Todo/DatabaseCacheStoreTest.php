<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\Todo;

use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
#[WithMigration('cache')]
class DatabaseCacheStoreTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped('Port after cache package is fully ported (missing forgetIfExpired, getConnection methods).');
    }

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

    public function testAddOperationShouldNotUpdateExistCache()
    {
        $store = $this->getStore();

        $store->add('foo', 'bar', 60);
        $result = $store->add('foo', 'new-bar', 60);

        $this->assertFalse($result);
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testAddOperationShouldNotUpdateExistCacheInTransaction()
    {
        $store = $this->getStore();

        $store->add('foo', 'bar', 60);

        DB::beginTransaction();
        $result = $store->add('foo', 'new-bar', 60);
        DB::commit();

        $this->assertFalse($result);
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testAddOperationCanUpdateIfCacheExpired()
    {
        $store = $this->getStore();

        $this->insertToCacheTable('foo', 'bar', 0);
        $result = $store->add('foo', 'new-bar', 60);

        $this->assertTrue($result);
        $this->assertSame('new-bar', $store->get('foo'));
    }

    public function testAddOperationCanUpdateIfCacheExpiredInTransaction()
    {
        $store = $this->getStore();

        $this->insertToCacheTable('foo', 'bar', 0);

        DB::beginTransaction();
        $result = $store->add('foo', 'new-bar', 60);
        DB::commit();

        $this->assertTrue($result);
        $this->assertSame('new-bar', $store->get('foo'));
    }

    public function testGetOperationReturnNullIfExpired()
    {
        $store = $this->getStore();

        $this->insertToCacheTable('foo', 'bar', 0);

        $result = $store->get('foo');

        $this->assertNull($result);
    }

    public function testGetOperationCanDeleteExpired()
    {
        $store = $this->getStore();

        $this->insertToCacheTable('foo', 'bar', 0);

        $store->get('foo');

        $this->assertDatabaseMissing($this->getCacheTableName(), ['key' => $this->withCachePrefix('foo')]);
    }

    public function testForgetIfExpiredOperationCanDeleteExpired()
    {
        $store = $this->getStore();

        $this->insertToCacheTable('foo', 'bar', 0);

        $store->forgetIfExpired('foo');

        $this->assertDatabaseMissing($this->getCacheTableName(), ['key' => $this->withCachePrefix('foo')]);
    }

    public function testForgetIfExpiredOperationShouldNotDeleteUnExpired()
    {
        $store = $this->getStore();

        $store->put('foo', 'bar', 60);

        $store->forgetIfExpired('foo');

        $this->assertDatabaseHas($this->getCacheTableName(), ['key' => $this->withCachePrefix('foo')]);
    }

    public function testMany()
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

    public function testManyWithExpiredKeys()
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

    public function testManyAsAssociativeArray()
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

    public function testPutMany()
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

    public function testResolvingSQLiteConnectionDoesNotThrowExceptions()
    {
        $originalConfiguration = config('database');

        app('config')->set('database.default', 'sqlite');
        app('config')->set('database.connections.sqlite.database', __DIR__ . '/non-existing-file');

        $store = $this->getStore();
        $this->assertInstanceOf(SQLiteConnection::class, $store->getConnection());

        app('config')->set('database', $originalConfiguration);
    }

    /**
     * @return \Hypervel\Cache\DatabaseStore
     */
    protected function getStore()
    {
        return Cache::store('database');
    }

    protected function getCacheTableName()
    {
        return config('cache.stores.database.table');
    }

    protected function withCachePrefix(string $key)
    {
        return config('cache.prefix') . $key;
    }

    protected function insertToCacheTable(string $key, $value, $ttl = 60)
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
}
