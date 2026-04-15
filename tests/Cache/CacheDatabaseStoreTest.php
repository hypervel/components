<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Carbon\Carbon;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;

class CacheDatabaseStoreTest extends TestCase
{
    public function testNullIsReturnedWhenItemNotFound()
    {
        [$store, $table] = $this->getStore();
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection);

        $this->assertNull($store->get('foo'));
    }

    public function testNullIsReturnedAndItemDeletedWhenItemIsExpired()
    {
        Carbon::setTestNow($now = Carbon::now());

        [$store, $table] = $this->getStore();

        // First call for retrieval
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection([(object) [
            'key' => 'prefixfoo',
            'value' => serialize('bar'),
            'expiration' => $now->copy()->subSeconds(10)->getTimestamp(),
        ]]));

        // Second call for deletion of expired items (includes flexible key cleanup)
        $table->shouldReceive('whereIn')->once()
            ->with('key', ['prefixfoo', 'prefixhypervel:cache:flexible:created:foo'])
            ->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '<=', $now->getTimestamp())->andReturn($table);
        $table->shouldReceive('delete')->once();

        $this->assertNull($store->get('foo'));
    }

    public function testDecryptedValueIsReturnedWhenItemIsValid()
    {
        [$store, $table] = $this->getStore();
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection([(object) ['key' => 'prefixfoo', 'value' => serialize('bar'), 'expiration' => 999999999999999]]));

        $this->assertSame('bar', $store->get('foo'));
    }

    public function testValueIsReturnedOnPostgres()
    {
        [$store, $table] = $this->getPostgresStore();
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection([(object) ['key' => 'prefixfoo', 'value' => base64_encode(serialize('bar')), 'expiration' => 999999999999999]]));

        $this->assertSame('bar', $store->get('foo'));
    }

    public function testValueIsReturnedOnSqlite()
    {
        [$store, $table] = $this->getSqliteStore();
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection([(object) ['key' => 'prefixfoo', 'value' => base64_encode(serialize("\0bar\0")), 'expiration' => 999999999999999]]));

        $this->assertSame("\0bar\0", $store->get('foo'));
    }

    public function testManyReturnsMultipleItems()
    {
        [$store, $table] = $this->getStore();
        $table->shouldReceive('whereIn')
            ->once()
            ->with('key', ['prefixfoo', 'prefixbar', 'prefixbaz'])
            ->andReturn($table);

        $table->shouldReceive('get')->once()->andReturn(new Collection([
            (object) [
                'key' => 'prefixfoo',
                'value' => serialize('bar'),
                'expiration' => 999999999999999,
            ],
            (object) [
                'key' => 'prefixbaz',
                'value' => serialize('qux'),
                'expiration' => 999999999999999,
            ],
        ]));

        $results = $store->many(['foo', 'bar', 'baz']);

        $this->assertEquals([
            'foo' => 'bar',
            'bar' => null,
            'baz' => 'qux',
        ], $results);
    }

    public function testExpiredItemsAreRemovedOnRetrieval()
    {
        Carbon::setTestNow($now = Carbon::now());

        [$store, $table] = $this->getStore();

        // First call for retrieval
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection([
            (object) [
                'key' => 'prefixfoo',
                'value' => serialize('bar'),
                'expiration' => $now->copy()->subSeconds(10)->getTimestamp(),
            ],
        ]));

        // Second call for deletion (includes flexible key cleanup)
        $table->shouldReceive('whereIn')
            ->once()
            ->with('key', ['prefixfoo', 'prefixhypervel:cache:flexible:created:foo'])
            ->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '<=', $now->getTimestamp())->andReturn($table);
        $table->shouldReceive('delete')->once();

        $this->assertNull($store->get('foo'));
    }

    public function testItemsCanBeStored()
    {
        [$store, $table] = $this->getStore();
        $table->shouldReceive('upsert')->once()->with(m::on(function ($arg) {
            return is_array($arg)
                && count($arg) === 1
                && $arg[0]['key'] === 'prefixfoo'
                && $arg[0]['value'] === serialize('bar')
                && is_int($arg[0]['expiration']);
        }), 'key')->andReturn(1);

        $result = $store->put('foo', 'bar', 10);
        $this->assertTrue($result);
    }

    public function testValueIsUpserted()
    {
        $store = $this->getMockBuilder(DatabaseStore::class)->onlyMethods(['getTime'])->setConstructorArgs($this->getMocks())->getMock();
        [$table] = $this->mockTable($store);
        $store->expects($this->once())->method('getTime')->willReturn(1);
        $table->shouldReceive('upsert')->once()->with([['key' => 'prefixfoo', 'value' => serialize('bar'), 'expiration' => 61]], 'key')->andReturnTrue();

        $result = $store->put('foo', 'bar', 60);
        $this->assertTrue($result);
    }

    public function testValueIsUpsertedOnPostgres()
    {
        $store = $this->getMockBuilder(DatabaseStore::class)->onlyMethods(['getTime'])->setConstructorArgs($this->getPostgresMocks())->getMock();
        [$table] = $this->mockTable($store);
        $store->expects($this->once())->method('getTime')->willReturn(1);
        $table->shouldReceive('upsert')->once()->with([['key' => 'prefixfoo', 'value' => base64_encode(serialize("\0")), 'expiration' => 61]], 'key')->andReturn(1);

        $result = $store->put('foo', "\0", 60);
        $this->assertTrue($result);
    }

    public function testValueIsUpsertedOnSqlite()
    {
        $store = $this->getMockBuilder(DatabaseStore::class)->onlyMethods(['getTime'])->setConstructorArgs($this->getSqliteMocks())->getMock();
        [$table] = $this->mockTable($store);
        $store->expects($this->once())->method('getTime')->willReturn(1);
        $table->shouldReceive('upsert')->once()->with([['key' => 'prefixfoo', 'value' => base64_encode(serialize("\0")), 'expiration' => 61]], 'key')->andReturn(1);

        $result = $store->put('foo', "\0", 60);
        $this->assertTrue($result);
    }

    public function testManyItemsCanBeStoredAtOnce()
    {
        [$store, $table] = $this->getStore();

        $table->shouldReceive('upsert')->once()->with(m::on(function ($arg) {
            return is_array($arg)
                && count($arg) === 2
                && $arg[0]['key'] === 'prefixfoo'
                && $arg[0]['value'] === serialize('bar')
                && is_int($arg[0]['expiration'])
                && $arg[1]['key'] === 'prefixbaz'
                && $arg[1]['value'] === serialize('qux')
                && is_int($arg[1]['expiration']);
        }), 'key')->andReturn(2);

        $result = $store->putMany(['foo' => 'bar', 'baz' => 'qux'], 10);
        $this->assertTrue($result);
    }

    public function testAddOnlyAddsIfKeyDoesntExist()
    {
        [$store, $table] = $this->getStore();

        // Check if exists (returns null)
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection);

        // Insert (uses insertOrIgnore for atomicity)
        $table->shouldReceive('insertOrIgnore')->once()->with(m::on(function ($arg) {
            return is_array($arg)
                && $arg['key'] === 'prefixfoo'
                && $arg['value'] === serialize('bar')
                && is_int($arg['expiration']);
        }))->andReturn(1);

        $this->assertTrue($store->add('foo', 'bar', 10));
    }

    public function testAddReturnsFalseIfKeyExists()
    {
        [$store, $table] = $this->getStore();
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection([
            (object) [
                'key' => 'prefixfoo',
                'value' => serialize('bar'),
                'expiration' => 999999999999999,
            ],
        ]));

        $this->assertFalse($store->add('foo', 'new-bar', 10));
    }

    public function testIncrementReturnsCorrectValues()
    {
        [$store, $table, $connection] = $this->getStore();

        $connection->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use ($connection) {
            return $callback($connection);
        });

        $table->shouldReceive('where')->once()->with('key', 'prefixfoo')->andReturn($table);
        $table->shouldReceive('lockForUpdate')->once()->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) [
            'key' => 'prefixfoo',
            'value' => serialize(2),
            'expiration' => 999999999999999,
        ]);

        $table->shouldReceive('where')->once()->with('key', 'prefixfoo')->andReturn($table);
        $table->shouldReceive('update')->once()->with(['value' => serialize(3)])->andReturn(1);

        $this->assertEquals(3, $store->increment('foo'));
    }

    public function testIncrementReturnsFalseIfItemNotNumeric()
    {
        [$store, $table, $connection] = $this->getStore();

        $connection->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use ($connection) {
            return $callback($connection);
        });

        $table->shouldReceive('where')->once()->with('key', 'prefixfoo')->andReturn($table);
        $table->shouldReceive('lockForUpdate')->once()->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) [
            'key' => 'prefixfoo',
            'value' => serialize('not-a-number'),
            'expiration' => 999999999999999,
        ]);

        $this->assertFalse($store->increment('foo'));
    }

    public function testDecrementReturnsCorrectValues()
    {
        [$store, $table, $connection] = $this->getStore();

        $connection->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use ($connection) {
            return $callback($connection);
        });

        $table->shouldReceive('where')->once()->with('key', 'prefixfoo')->andReturn($table);
        $table->shouldReceive('lockForUpdate')->once()->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) [
            'key' => 'prefixfoo',
            'value' => serialize(10),
            'expiration' => 999999999999999,
        ]);

        $table->shouldReceive('where')->once()->with('key', 'prefixfoo')->andReturn($table);
        $table->shouldReceive('update')->once()->with(['value' => serialize(7)])->andReturn(1);

        $this->assertEquals(7, $store->decrement('foo', 3));
    }

    public function testTouchExtendsTtl()
    {
        $store = $this->getMockBuilder(DatabaseStore::class)->onlyMethods(['getTime'])->setConstructorArgs($this->getMocks())->getMock();
        [$table] = $this->mockTable($store);

        $store->expects($this->once())->method('getTime')->willReturn(0);
        $table->shouldReceive('where')->twice()->andReturn($table);
        $table->shouldReceive('update')->once()->with(['expiration' => 60])->andReturn(1);

        $this->assertTrue($store->touch('foo', 60));
    }

    public function testTouchExtendsTtlOnPostgres()
    {
        $store = $this->getMockBuilder(DatabaseStore::class)->onlyMethods(['getTime'])->setConstructorArgs($this->getPostgresMocks())->getMock();
        [$table] = $this->mockTable($store);

        $store->expects($this->once())->method('getTime')->willReturn(0);
        $table->shouldReceive('where')->twice()->andReturn($table);
        $table->shouldReceive('update')->once()->with(['expiration' => 60])->andReturn(1);

        $this->assertTrue($store->touch('foo', 60));
    }

    public function testTouchExtendsTtlOnSqlite()
    {
        $store = $this->getMockBuilder(DatabaseStore::class)->onlyMethods(['getTime'])->setConstructorArgs($this->getSqliteMocks())->getMock();
        [$table] = $this->mockTable($store);

        $store->expects($this->once())->method('getTime')->willReturn(0);
        $table->shouldReceive('where')->twice()->andReturn($table);
        $table->shouldReceive('update')->once()->with(['expiration' => 60])->andReturn(1);

        $this->assertTrue($store->touch('foo', 60));
    }

    public function testForeverCallsStoreItemWithReallyLongTime()
    {
        $store = $this->getMockBuilder(DatabaseStore::class)
            ->onlyMethods(['put'])
            ->setConstructorArgs($this->getMocks())
            ->getMock();

        $store->expects($this->once())
            ->method('put')
            ->with($this->equalTo('foo'), $this->equalTo('bar'), $this->equalTo(315360000))
            ->willReturn(true);

        $result = $store->forever('foo', 'bar');
        $this->assertTrue($result);
    }

    public function testItemsMayBeRemovedFromCache()
    {
        [$store, $table] = $this->getStore();
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo', 'prefixhypervel:cache:flexible:created:foo'])->andReturn($table);
        $table->shouldReceive('delete')->once();

        $store->forget('foo');
    }

    public function testItemsMayBeFlushedFromCache()
    {
        [$store, $table] = $this->getStore();
        $table->shouldReceive('delete')->once()->andReturn(2);

        $result = $store->flush();
        $this->assertTrue($result);
    }

    public function testLocksMayBeFlushedFromCache()
    {
        [$store, , , $resolver] = $this->getStore();
        $lockConnection = m::mock(ConnectionInterface::class);
        $lockTable = m::mock(Builder::class);
        $resolver->shouldReceive('connection')->with('locks')->andReturn($lockConnection);
        $lockConnection->shouldReceive('table')->once()->with('cache_locks')->andReturn($lockTable);
        $lockTable->shouldReceive('delete')->once()->andReturn(2);

        $store->setLockConnection('locks');
        $result = $store->flushLocks();
        $this->assertTrue($result);
    }

    public function testPruneExpiredRemovesExpiredEntries()
    {
        Carbon::setTestNow($now = Carbon::now());

        [$store, $table] = $this->getStore();
        $table->shouldReceive('where')->once()->with('expiration', '<=', $now->getTimestamp())->andReturn($table);
        $table->shouldReceive('delete')->once()->andReturn(5);

        $this->assertEquals(5, $store->pruneExpired());
    }

    public function testGetPrefixReturnsConfiguredPrefix()
    {
        [$store] = $this->getStore();
        $this->assertSame('prefix', $store->getPrefix());
    }

    /**
     * Get a DatabaseStore instance with mocked dependencies.
     */
    protected function getStore(): array
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);

        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->with('table')->andReturn($table);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');

        return [$store, $table, $connection, $resolver];
    }

    /**
     * Get a DatabaseStore instance with a mocked Postgres connection.
     */
    protected function getPostgresStore(): array
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(PostgresConnection::class);
        $table = m::mock(Builder::class);

        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->with('table')->andReturn($table);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');

        return [$store, $table, $connection, $resolver];
    }

    /**
     * Get a DatabaseStore instance with a mocked SQLite connection.
     */
    protected function getSqliteStore(): array
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(SQLiteConnection::class);
        $table = m::mock(Builder::class);

        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->with('table')->andReturn($table);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');

        return [$store, $table, $connection, $resolver];
    }

    /**
     * Get mock arguments for creating a DatabaseStore.
     */
    protected function getMocks(): array
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with('default')->andReturn(m::mock(ConnectionInterface::class));

        return [$resolver, 'default', 'table', 'prefix'];
    }

    /**
     * Get mock arguments for creating a DatabaseStore with Postgres connection.
     */
    protected function getPostgresMocks(): array
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with('default')->andReturn(m::mock(PostgresConnection::class));

        return [$resolver, 'default', 'table', 'prefix'];
    }

    /**
     * Get mock arguments for creating a DatabaseStore with SQLite connection.
     */
    protected function getSqliteMocks(): array
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with('default')->andReturn(m::mock(SQLiteConnection::class));

        return [$resolver, 'default', 'table', 'prefix'];
    }

    /**
     * Set up a table mock on an already-created store.
     *
     * Used when the store is created via getMockBuilder (for stubbing methods like getTime)
     * where we can't use the getStore() helper. Gets the connection from the store's
     * resolver (which was set up by getMocks/getPostgresMocks/getSqliteMocks) and
     * configures the table mock.
     */
    protected function mockTable(DatabaseStore $store): array
    {
        $table = m::mock(Builder::class);
        $connection = $store->getConnection();
        $connection->shouldReceive('table')->with('table')->andReturn($table);

        return [$table];
    }
}
