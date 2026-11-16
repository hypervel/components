<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use __PHP_Incomplete_Class;
use Closure;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Cache\SerializableClassPolicy;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use stdClass;

class CacheDatabaseStoreTest extends TestCase
{
    use VerifiesDoubles;

    public function testNullIsReturnedWhenItemNotFound(): void
    {
        [$store, $table] = $this->getStore();
        $table->expects('whereIn')->with('key', ['prefixfoo'])->returns($table);
        $table->expects('get')->returns(new Collection);

        $this->assertNull($store->get('foo'));
    }

    public function testNullIsReturnedAndItemDeletedWhenItemIsExpired(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        [$store, $table] = $this->getStore();

        // First call for retrieval
        $table->expects('whereIn')->with('key', ['prefixfoo'])->returns($table);
        $table->expects('get')->returns(new Collection([(object) [
            'key' => 'prefixfoo',
            'value' => serialize('bar'),
            'expiration' => $now->copy()->subSeconds(10)->getTimestamp(),
        ]]));

        // Second call for deletion of expired items (includes flexible key cleanup)
        $table->expects('whereIn')
            ->with('key', ['prefixfoo', 'prefixhypervel:cache:flexible:created:foo'])
            ->returns($table);
        $table->expects('where')->with('expiration', '<=', $now->getTimestamp())->returns($table);
        $table->expects('delete');

        $this->assertNull($store->get('foo'));
    }

    public function testDecryptedValueIsReturnedWhenItemIsValid(): void
    {
        [$store, $table] = $this->getStore();
        $table->expects('whereIn')->with('key', ['prefixfoo'])->returns($table);
        $table->expects('get')->returns(new Collection([(object) ['key' => 'prefixfoo', 'value' => serialize('bar'), 'expiration' => 999999999999999]]));

        $this->assertSame('bar', $store->get('foo'));
    }

    public function testSerializableClassesControlCachedObjects(): void
    {
        [$denyingStore, $denyingTable] = $this->getStore(false);
        [$allowingStore, $allowingTable] = $this->getStore([stdClass::class]);

        foreach ([$denyingTable, $allowingTable] as $table) {
            $table->expects('whereIn')->with('key', ['prefixfoo'])->returns($table);
            $table->expects('get')->returns(new Collection([(object) [
                'key' => 'prefixfoo',
                'value' => serialize(new stdClass),
                'expiration' => 999999999999999,
            ]]));
        }

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $denyingStore->get('foo'));
        $this->assertInstanceOf(stdClass::class, $allowingStore->get('foo'));
    }

    public function testSerializableClassPolicyControlsCachedObjects(): void
    {
        [$denyingStore, $denyingTable] = $this->getStore(
            serializableClassPolicy: new SerializableClassPolicy(static fn (): false => false),
        );
        [$allowingStore, $allowingTable] = $this->getStore(
            serializableClassPolicy: new SerializableClassPolicy(static fn (): array => [stdClass::class]),
        );

        foreach ([$denyingTable, $allowingTable] as $table) {
            $table->expects('whereIn')->with('key', ['prefixfoo'])->returns($table);
            $table->expects('get')->returns(new Collection([(object) [
                'key' => 'prefixfoo',
                'value' => serialize(new stdClass),
                'expiration' => 999999999999999,
            ]]));
        }

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $denyingStore->get('foo'));
        $this->assertInstanceOf(stdClass::class, $allowingStore->get('foo'));
    }

    public function testValueIsReturnedOnPostgres(): void
    {
        [$store, $table] = $this->getPostgresStore();
        $table->expects('whereIn')->with('key', ['prefixfoo'])->returns($table);
        $table->expects('get')->returns(new Collection([(object) ['key' => 'prefixfoo', 'value' => base64_encode(serialize('bar')), 'expiration' => 999999999999999]]));

        $this->assertSame('bar', $store->get('foo'));
    }

    public function testValueIsReturnedOnSqlite(): void
    {
        [$store, $table] = $this->getSqliteStore();
        $table->expects('whereIn')->with('key', ['prefixfoo'])->returns($table);
        $table->expects('get')->returns(new Collection([(object) ['key' => 'prefixfoo', 'value' => base64_encode(serialize("\0bar\0")), 'expiration' => 999999999999999]]));

        $this->assertSame("\0bar\0", $store->get('foo'));
    }

    public function testManyReturnsMultipleItems(): void
    {
        [$store, $table] = $this->getStore();
        $table->expects('whereIn')
            ->with('key', ['prefixfoo', 'prefixbar', 'prefixbaz'])
            ->returns($table);

        $table->expects('get')->returns(new Collection([
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

        $this->assertSame([
            'foo' => 'bar',
            'bar' => null,
            'baz' => 'qux',
        ], $results);
    }

    public function testValueIsUpserted(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(1));

        [$store, $table] = $this->getStore();
        $table->expects('upsert')->with([['key' => 'prefixfoo', 'value' => serialize('bar'), 'expiration' => 61]], 'key')->returns(1);

        $result = $store->put('foo', 'bar', 60);
        $this->assertTrue($result);
    }

    public function testValueIsUpsertedOnPostgres(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(1));

        [$store, $table] = $this->getPostgresStore();
        $table->expects('upsert')->with([['key' => 'prefixfoo', 'value' => base64_encode(serialize("\0")), 'expiration' => 61]], 'key')->returns(1);

        $result = $store->put('foo', "\0", 60);
        $this->assertTrue($result);
    }

    public function testValueIsUpsertedOnSqlite(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(1));

        [$store, $table] = $this->getSqliteStore();
        $table->expects('upsert')->with([['key' => 'prefixfoo', 'value' => base64_encode(serialize("\0")), 'expiration' => 61]], 'key')->returns(1);

        $result = $store->put('foo', "\0", 60);
        $this->assertTrue($result);
    }

    public function testManyItemsCanBeStoredAtOnce(): void
    {
        [$store, $table] = $this->getStore();

        $table->expects('upsert')->with(Argument::satisfies(function (mixed $arg): bool {
            return is_array($arg)
                && count($arg) === 2
                && $arg[0]['key'] === 'prefixfoo'
                && $arg[0]['value'] === serialize('bar')
                && is_int($arg[0]['expiration'])
                && $arg[1]['key'] === 'prefixbaz'
                && $arg[1]['value'] === serialize('qux')
                && is_int($arg[1]['expiration']);
        }), 'key')->returns(2);

        $result = $store->putMany(['foo' => 'bar', 'baz' => 'qux'], 10);
        $this->assertTrue($result);
    }

    public function testPutManyReturnsTrueForEmptyInputWithoutUpserting(): void
    {
        [$store, $table] = $this->getStore();

        $table->expects('upsert')->never();

        $this->assertTrue($store->putMany([], 10));
    }

    public function testPutManyReturnsTrueWhenUpsertAffectsNoRows(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(1));

        [$store, $table] = $this->getStore();
        $table->expects('upsert')->with([['key' => 'prefixfoo', 'value' => serialize('bar'), 'expiration' => 61]], 'key')->returns(0);

        $this->assertTrue($store->putMany(['foo' => 'bar'], 60));
    }

    public function testPutReturnsTrueWhenDelegatedUpsertAffectsNoRows(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(1));

        [$store, $table] = $this->getStore();
        $table->expects('upsert')->with([['key' => 'prefixfoo', 'value' => serialize('bar'), 'expiration' => 61]], 'key')->returns(0);

        $this->assertTrue($store->put('foo', 'bar', 60));
    }

    public function testAddOnlyAddsIfKeyDoesntExist(): void
    {
        [$store, $table] = $this->getStore();

        // Check if exists (returns null)
        $table->expects('whereIn')->with('key', ['prefixfoo'])->returns($table);
        $table->expects('get')->returns(new Collection);

        // Insert (uses insertOrIgnore for atomicity)
        $table->expects('insertOrIgnore')->with(Argument::satisfies(function (mixed $arg): bool {
            return is_array($arg)
                && $arg['key'] === 'prefixfoo'
                && $arg['value'] === serialize('bar')
                && is_int($arg['expiration']);
        }))->returns(1);

        $this->assertTrue($store->add('foo', 'bar', 10));
    }

    public function testFractionalSecondWritesNeverExpireBeforeRequestedDeadline(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        [$store, $table] = $this->getStore();

        $table->expects('upsert')->with([
            ['key' => 'prefixfoo', 'value' => serialize('bar'), 'expiration' => 1002],
        ], 'key')->returns(1);

        $this->assertTrue($store->put('foo', 'bar', 1));

        $table->expects('whereIn')->with('key', ['prefixnew'])->returns($table);
        $table->expects('get')->returns(new Collection);
        $table->expects('insertOrIgnore')->with([
            'key' => 'prefixnew',
            'value' => serialize('value'),
            'expiration' => 1002,
        ])->returns(1);

        $this->assertTrue($store->add('new', 'value', 1));

        $table->expects('where')->with('key', '=', 'prefixtouched')->returns($table);
        $table->expects('where')->with('expiration', '>', 1000)->returns($table);
        $table->expects('update')->with(['expiration' => 1002])->returns(1);

        $this->assertTrue($store->touch('touched', 1));
    }

    public function testAddReturnsFalseIfKeyExists(): void
    {
        [$store, $table] = $this->getStore();
        $table->expects('whereIn')->with('key', ['prefixfoo'])->returns($table);
        $table->expects('get')->returns(new Collection([
            (object) [
                'key' => 'prefixfoo',
                'value' => serialize('bar'),
                'expiration' => 999999999999999,
            ],
        ]));

        $this->assertFalse($store->add('foo', 'new-bar', 10));
    }

    public function testForeverCallsStoreItemWithReallyLongTime(): void
    {
        $store = $this->getMockBuilder(DatabaseStore::class)
            ->onlyMethods(['put'])
            ->setConstructorArgs($this->getMocks())
            ->getMock();

        $store->expects($this->once())
            ->method('put')
            ->with('foo', 'bar', 315360000)
            ->willReturn(true);

        $result = $store->forever('foo', 'bar');
        $this->assertTrue($result);
    }

    public function testItemsMayBeRemovedFromCache(): void
    {
        [$store, $table] = $this->getStore();
        $table->expects('whereIn')->with('key', ['prefixfoo', 'prefixhypervel:cache:flexible:created:foo'])->returns($table);
        $table->expects('delete');

        $store->forget('foo');
    }

    public function testItemsMayBeFlushedFromCache(): void
    {
        [$store, $table] = $this->getStore();
        $table->expects('delete')->returns(2);

        $result = $store->flush();
        $this->assertTrue($result);
    }

    public function testLocksMayBeFlushedFromCache(): void
    {
        [$store, , , $resolver] = $this->getStore();
        $lockConnection = Double::for(Connection::class);
        $lockTable = Double::for(Builder::class);
        $resolver->allows('connection')->with('locks')->returns($lockConnection);
        $lockConnection->expects('table')->with('cache_locks')->returns($lockTable);
        $lockTable->expects('delete')->returns(2);

        $store->setLockConnection('locks');
        $result = $store->flushLocks();
        $this->assertTrue($result);
    }

    public function testIncrementReturnsCorrectValues(): void
    {
        [$store, $table, $connection] = $this->getStore();
        $cache = new stdClass;

        $connection->expects('transaction')->with(Argument::type(Closure::class))->times(3)->resolves(function (Closure $closure) use ($connection): mixed {
            return $closure($connection);
        });
        $table->expects('where')->with('key', 'prefixfoo')->times(4)->returns($table);
        $table->expects('lockForUpdate')->times(3)->returns($table);
        $table->expects('first')->times(3)->returns(null, $cache, $cache);
        $table->expects('update')->with(['value' => serialize(3)]);

        $this->assertFalse($store->increment('foo'));

        $cache->value = serialize('bar');
        $this->assertFalse($store->increment('foo'));

        $cache->value = serialize(2);
        $this->assertSame(3, $store->increment('foo'));
    }

    public function testDecrementReturnsCorrectValues(): void
    {
        [$store, $table, $connection] = $this->getStore();
        $cache = new stdClass;

        $connection->expects('transaction')->with(Argument::type(Closure::class))->times(4)->resolves(function (Closure $closure) use ($connection): mixed {
            return $closure($connection);
        });
        $table->expects('where')->with('key', 'prefixfoo')->times(4)->returns($table);
        $table->expects('where')->with('key', 'prefixbar')->times(2)->returns($table);
        $table->expects('lockForUpdate')->times(4)->returns($table);
        $table->expects('first')->times(4)->returns(null, $cache, $cache, $cache);
        $table->expects('update')->with(['value' => serialize(2)]);
        $table->expects('update')->with(['value' => serialize(7)]);

        $this->assertFalse($store->decrement('foo'));

        $cache->value = serialize('bar');
        $this->assertFalse($store->decrement('foo'));

        $cache->value = serialize(3);
        $this->assertSame(2, $store->decrement('bar'));

        $cache->value = serialize(10);
        $this->assertSame(7, $store->decrement('foo', 3));
    }

    public function testTouchExtendsTtl(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(0));

        $store = $this->getMockBuilder(DatabaseStore::class)->onlyMethods(['getTime'])->setConstructorArgs($this->getMocks())->getMock();
        [$table] = $this->mockTable($store);

        $store->expects($this->once())->method('getTime')->willReturn(0);
        $table->expects('where')->times(2)->returns($table);
        $table->expects('update')->with(['expiration' => 60])->returns(1);

        $this->assertTrue($store->touch('foo', 60));
    }

    public function testTouchExtendsTtlOnPostgres(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(0));

        $store = $this->getMockBuilder(DatabaseStore::class)->onlyMethods(['getTime'])->setConstructorArgs($this->getPostgresMocks())->getMock();
        [$table] = $this->mockTable($store);

        $store->expects($this->once())->method('getTime')->willReturn(0);
        $table->expects('where')->times(2)->returns($table);
        $table->expects('update')->with(['expiration' => 60])->returns(1);

        $this->assertTrue($store->touch('foo', 60));
    }

    public function testTouchExtendsTtlOnSqlite(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(0));

        $store = $this->getMockBuilder(DatabaseStore::class)->onlyMethods(['getTime'])->setConstructorArgs($this->getSqliteMocks())->getMock();
        [$table] = $this->mockTable($store);

        $store->expects($this->once())->method('getTime')->willReturn(0);
        $table->expects('where')->times(2)->returns($table);
        $table->expects('update')->with(['expiration' => 60])->returns(1);

        $this->assertTrue($store->touch('foo', 60));
    }

    public function testSupportsFlushingLocksRequiresSeparateLockTable(): void
    {
        [$store] = $this->getStore();

        $this->assertTrue($store->supportsFlushingLocks());

        $store = new DatabaseStore(Double::for(ConnectionResolverInterface::class), 'default', 'cache', '', 'cache');

        $this->assertFalse($store->supportsFlushingLocks());
    }

    public function testPruneExpiredRemovesExpiredEntries(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        [$store, $table] = $this->getStore();
        $table->expects('where')->with('expiration', '<=', $now->getTimestamp())->returns($table);
        $table->expects('delete')->returns(5);

        $this->assertSame(5, $store->pruneExpired());
    }

    public function testGetPrefixReturnsConfiguredPrefix(): void
    {
        [$store] = $this->getStore();
        $this->assertSame('prefix', $store->getPrefix());
    }

    /**
     * Get a DatabaseStore instance with mocked dependencies.
     */
    protected function getStore(
        array|bool|null $serializableClasses = null,
        ?SerializableClassPolicy $serializableClassPolicy = null,
    ): array {
        $resolver = Double::for(ConnectionResolverInterface::class);
        $connection = Double::for(Connection::class);
        $table = Double::for(Builder::class);

        $resolver->allows('connection')->with('default')->returns($connection);
        $connection->allows('table')->with('table')->returns($table);

        $store = new DatabaseStore(
            $resolver,
            'default',
            'table',
            'prefix',
            serializableClasses: $serializableClasses,
            serializableClassPolicy: $serializableClassPolicy,
        );

        return [$store, $table, $connection, $resolver];
    }

    /**
     * Get a DatabaseStore instance with a mocked Postgres connection.
     */
    protected function getPostgresStore(): array
    {
        $resolver = Double::for(ConnectionResolverInterface::class);
        $connection = Double::for(PostgresConnection::class);
        $table = Double::for(Builder::class);

        $resolver->allows('connection')->with('default')->returns($connection);
        $connection->allows('table')->with('table')->returns($table);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');

        return [$store, $table, $connection, $resolver];
    }

    /**
     * Get a DatabaseStore instance with a mocked SQLite connection.
     */
    protected function getSqliteStore(): array
    {
        $resolver = Double::for(ConnectionResolverInterface::class);
        $connection = Double::for(SQLiteConnection::class);
        $table = Double::for(Builder::class);

        $resolver->allows('connection')->with('default')->returns($connection);
        $connection->allows('table')->with('table')->returns($table);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');

        return [$store, $table, $connection, $resolver];
    }

    /**
     * Get mock arguments for creating a DatabaseStore.
     */
    protected function getMocks(): array
    {
        $resolver = Double::for(ConnectionResolverInterface::class);
        $resolver->allows('connection')->with('default')->returns(Double::for(Connection::class));

        return [$resolver, 'default', 'table', 'prefix'];
    }

    /**
     * Get mock arguments for creating a DatabaseStore with Postgres connection.
     */
    protected function getPostgresMocks(): array
    {
        $resolver = Double::for(ConnectionResolverInterface::class);
        $resolver->allows('connection')->with('default')->returns(Double::for(PostgresConnection::class));

        return [$resolver, 'default', 'table', 'prefix'];
    }

    /**
     * Get mock arguments for creating a DatabaseStore with SQLite connection.
     */
    protected function getSqliteMocks(): array
    {
        $resolver = Double::for(ConnectionResolverInterface::class);
        $resolver->allows('connection')->with('default')->returns(Double::for(SQLiteConnection::class));

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
        $table = Double::for(Builder::class);
        $connection = $store->getConnection();
        $connection->allows('table')->with('table')->returns($table);

        return [$table];
    }
}
