<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Cache\DatabaseLock;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\QueryException;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\WithMigration;
use Mockery as m;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;

#[WithMigration('cache')]
class DatabaseLockTest extends DatabaseTestCase
{
    public function testLockCanHaveASeparateConnection(): void
    {
        $config = $this->app->make('config');

        $config->set('cache.stores.database.lock_connection', 'test');
        $config->set('database.connections.test', $config->array('database.connections.testing'));

        $this->assertSame('test', Cache::driver('database')->lock('foo')->getConnectionName());
    }

    public function testLockCanBeAcquired(): void
    {
        $lock = Cache::driver('database')->lock('foo');
        $this->assertTrue($lock->get());

        $otherLock = Cache::driver('database')->lock('foo');
        $this->assertFalse($otherLock->get());

        $lock->release();

        $otherLock = Cache::driver('database')->lock('foo');
        $this->assertTrue($otherLock->get());

        $otherLock->release();
    }

    public function testOverlongLockNamesFailWithoutLeavingATruncatedLock(): void
    {
        if (! in_array($this->driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MySQL and MariaDB truncate overlong names with INSERT IGNORE.');
        }

        try {
            Cache::store('database')->lock(str_repeat('a', 300))->get();
            $this->fail('The overlong lock name was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('22001', $exception->getCode());
        }

        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    public function testOwnerCanAcquireItsLiveLockAgainWithinTheSameSecond(): void
    {
        // A frozen clock gives the second acquisition the expiration the lock already has, so its update changes nothing.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());

        $lock = Cache::driver('database')->lock('foo', 10);
        $this->assertTrue($lock->get());
        $this->assertTrue($lock->get());

        $this->assertFalse(Cache::driver('database')->lock('foo', 10)->get());

        $lock->release();
    }

    public function testOwnerCanRefreshItsLockToTheExpirationItAlreadyHas(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());

        $lock = Cache::driver('database')->lock('foo', 10);
        $this->assertTrue($lock->get());

        $this->assertTrue($lock->refresh());
        $this->assertFalse(Cache::store('database')->restoreLock('foo', 'other_owner')->refresh());

        $lock->release();

        $this->assertFalse($lock->refresh());
    }

    #[TestWith(['get'])]
    #[TestWith(['refresh'])]
    public function testAnUnchangedUpdateIsConfirmedAgainstTheCurrentRowRatherThanATransactionSnapshot(string $operation): void
    {
        if (! in_array($this->driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MySQL and MariaDB count changed rows, so only they read the lock back after an update.');
        }

        $config = $this->app->make('config');
        $config->set('database.connections.other', $config->array('database.connections.' . $config->string('database.default')));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
        $lock = Cache::driver('database')->lock('foo', 10);
        $this->assertTrue($lock->get());
        $snapshotIsolation = $this->driver === 'mariadb'
            && (DB::selectOne("show variables like 'innodb_snapshot_isolation'")?->Value ?? 'OFF') === 'ON';
        DB::statement('set transaction isolation level repeatable read');

        if ($snapshotIsolation) {
            // MariaDB rolls back the transaction when a locking operation encounters the newer row.
            $this->expectException(QueryException::class);
            $this->expectExceptionMessage('1020 Record has changed since last read');
        }

        DB::transaction(function () use ($lock, $operation): void {
            // The first read fixes the transaction's snapshot while the lock is still this owner's.
            $this->assertSame($lock->owner(), DB::table('cache_locks')->value('owner'));
            // Another connection gives the live lock to another owner and commits.
            DB::connection('other')->table('cache_locks')->update(['owner' => 'other_owner']);

            $this->assertFalse($lock->{$operation}());
        });
    }

    public function testLockCanBeForceReleased(): void
    {
        $lock = Cache::driver('database')->lock('foo');
        $this->assertTrue($lock->get());

        $otherLock = Cache::driver('database')->lock('foo');
        $otherLock->forceRelease();
        $this->assertTrue($otherLock->get());

        $otherLock->release();
    }

    public function testExpiredLockCanBeRetrieved(): void
    {
        $lock = Cache::driver('database')->lock('foo');
        $this->assertTrue($lock->get());
        DB::table('cache_locks')->update(['expiration' => CarbonImmutable::now()->subDay()->getTimestamp()]);

        $otherLock = Cache::driver('database')->lock('foo');
        $this->assertTrue($otherLock->get());

        $otherLock->release();
    }

    public function testLockCannotBeAcquiredWhileHeldInsideTransaction(): void
    {
        $lock = Cache::driver('database')->lock('foo');
        $this->assertTrue($lock->get());

        DB::transaction(function (): void {
            $this->assertFalse(Cache::driver('database')->lock('foo')->get());

            $this->assertSame(1, DB::table('cache_locks')->count());
        });

        $lock->release();
    }

    public function testExpiredLockCanBeAcquiredInsideTransaction(): void
    {
        $lock = Cache::driver('database')->lock('foo');
        $this->assertTrue($lock->get());
        DB::table('cache_locks')->update(['expiration' => CarbonImmutable::now()->subDay()->getTimestamp()]);

        DB::transaction(function (): void {
            $otherLock = Cache::driver('database')->lock('foo');
            $this->assertTrue($otherLock->get());

            $otherLock->release();
        });
    }

    public function testIsLocked(): void
    {
        $lock = Cache::driver('database')->lock('foo');
        $this->assertFalse($lock->isLocked());

        $lock->get();
        $this->assertTrue($lock->isLocked());

        $lock->release();
        $this->assertFalse($lock->isLocked());
    }

    public function testExpiredLockIsNotLocked(): void
    {
        $lock = Cache::driver('database')->lock('foo');
        $this->assertFalse($lock->isLocked());

        $lock->get();
        $this->assertTrue($lock->isLocked());

        DB::table('cache_locks')->update(['expiration' => CarbonImmutable::now()->subDay()->getTimestamp()]);
        $this->assertFalse($lock->isLocked());
    }

    public function testOtherOwnerDoesNotOwnLockAfterRestore(): void
    {
        $firstLock = Cache::store('database')->lock('foo');
        $this->assertTrue($firstLock->isOwnedBy(null));
        $this->assertTrue($firstLock->get());
        $this->assertTrue($firstLock->isOwnedBy($firstLock->owner()));

        $secondLock = Cache::store('database')->restoreLock('foo', 'other_owner');
        $this->assertTrue($secondLock->isOwnedBy($firstLock->owner()));
        $this->assertFalse($secondLock->isOwnedByCurrentProcess());
        $this->assertFalse($secondLock->release());
        $this->assertTrue($firstLock->isOwnedByCurrentProcess());
    }

    public function testExpiredLockCanBeReleasedByItsOwner(): void
    {
        $lock = Cache::store('database')->lock('foo', 10);
        $this->assertTrue($lock->get());

        DB::table('cache_locks')->update(['expiration' => CarbonImmutable::now()->subDay()->getTimestamp()]);

        $this->assertTrue($lock->release());
        $this->assertSame(0, DB::table('cache_locks')->count());
        $this->assertFalse($lock->release());
    }

    public function testLockCanBeRefreshed(): void
    {
        $lock = Cache::driver('database')->lock('foo', 10);
        $this->assertTrue($lock->get());

        // Refresh the lock for another 20 seconds
        $this->assertTrue($lock->refresh(20));

        // Lock should still be held
        $this->assertFalse(Cache::driver('database')->lock('foo', 10)->get());

        $lock->release();
    }

    public function testLockCannotBeRefreshedByAnotherOwner(): void
    {
        $firstLock = Cache::driver('database')->lock('foo', 10);
        $this->assertTrue($firstLock->get());

        // Create a new lock with a different owner
        $secondLock = Cache::store('database')->restoreLock('foo', 'other_owner');

        // Second lock should not be able to refresh
        $this->assertFalse($secondLock->refresh(20));

        // Original lock should still be able to refresh
        $this->assertTrue($firstLock->refresh(20));

        $firstLock->release();
    }

    public function testExpiredLockCannotBeRefreshedByPreviousOwner(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $lock = Cache::driver('database')->lock('foo', 10);
        $this->assertTrue($lock->get());

        DB::table('cache_locks')->update(['expiration' => $now->subDay()->getTimestamp()]);

        $this->assertFalse($lock->refresh(20));
    }

    #[TestWith(['Deadlock found when trying to get lock', 1213, true])]
    #[TestWith(['Table does not exist', 1146, false])]
    public function testIgnoresConcurrencyException(string $message, int $code, bool $hasConcurrencyError): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(Connection::class);
        $insertBuilder = m::mock(Builder::class);
        $deleteBuilder = m::mock(Builder::class);

        $insertBuilder->expects('insert')->andReturn(true);

        $deleteBuilder->expects('where')->with('expiration', '<=', m::any())->andReturnSelf();
        $deleteBuilder->expects('delete')->andThrow(
            new QueryException(
                'mysql',
                'delete from cache_locks where expiration <= ?',
                [],
                new PDOException($message, $code)
            )
        );

        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->allows('transactionLevel')->andReturn(0);
        $connection->expects('table')->times(2)->with('cache_locks')->andReturn($insertBuilder, $deleteBuilder);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);

        $lock = new DatabaseLock($resolver, null, 'foo', 'cache_locks', 0, lottery: [1, 1]);

        if ($hasConcurrencyError) {
            $this->assertTrue($lock->acquire());
        } else {
            $this->expectException(QueryException::class);
            $this->assertFalse($lock->acquire());
        }
    }

    public function testAcquireThrowsConcurrencyExceptionInsideTransaction(): void
    {
        // REMOVED: upstream's case where a deadlock outside a transaction falls back to a takeover. Insert failures other
        // than an existing lock always propagate; see CacheDatabaseLockTest::testInsertFailureOtherThanAnExistingLockPropagatesWithoutTakingOver().
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(Connection::class);
        $insertBuilder = m::mock(Builder::class);

        $insertBuilder->expects('insert')->andThrow(
            new QueryException(
                'mysql',
                'insert into cache_locks (key, owner, expiration) values (?, ?, ?)',
                [],
                new PDOException('Deadlock found when trying to get lock', 1213)
            )
        );

        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->allows('transactionLevel')->andReturn(1);
        $connection->expects('table')->with('cache_locks')->andReturn($insertBuilder);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);

        $lock = new DatabaseLock($resolver, null, 'foo', 'cache_locks', 10, lottery: null);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIsOrContains('Deadlock found when trying to get lock');
        $lock->acquire();
    }

    #[TestWith(['Serialization failure: 1213 Deadlock', 40001, true])]
    #[TestWith(['Table does not exist', 1146, false])]
    public function testReleaseIgnoresConcurrencyException(string $message, int $code, bool $hasConcurrencyError): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(Connection::class);
        $deleteBuilder = m::mock(Builder::class);

        $owner = 'owner-123';

        $deleteBuilder->expects('where')->with('key', 'foo')->andReturnSelf();
        $deleteBuilder->expects('where')->with('owner', $owner)->andReturnSelf();
        $deleteBuilder->expects('delete')->andThrow(
            new QueryException(
                'mysql',
                'delete from cache_locks where key = ? and owner = ?',
                ['foo', $owner],
                new PDOException($message, $code)
            )
        );

        $connection->allows('transactionLevel')->andReturn(0);
        $connection->expects('table')->with('cache_locks')->andReturn($deleteBuilder);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);

        $lock = new DatabaseLock($resolver, null, 'foo', 'cache_locks', 10, $owner);

        if ($hasConcurrencyError) {
            $this->assertTrue($lock->release());
        } else {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessageIsOrContains($message);
            $lock->release();
        }
    }

    public function testPruneThrowsConcurrencyExceptionInsideTransaction(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(Connection::class);
        $deleteBuilder = m::mock(Builder::class);

        $deleteBuilder->expects('where')->with('expiration', '<=', m::any())->andReturnSelf();
        $deleteBuilder->expects('delete')->andThrow(
            new QueryException(
                'mysql',
                'delete from cache_locks where expiration <= ?',
                [],
                new PDOException('Deadlock found when trying to get lock', 1213)
            )
        );

        $connection->allows('transactionLevel')->andReturn(1);
        $connection->expects('table')->with('cache_locks')->andReturn($deleteBuilder);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);

        $lock = new DatabaseLock($resolver, null, 'foo', 'cache_locks', 10);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIsOrContains('Deadlock found when trying to get lock');
        $lock->pruneExpiredLocks();
    }

    public function testReleaseThrowsConcurrencyExceptionInsideTransaction(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(Connection::class);
        $deleteBuilder = m::mock(Builder::class);

        $owner = 'owner-123';

        $deleteBuilder->expects('where')->with('key', 'foo')->andReturnSelf();
        $deleteBuilder->expects('where')->with('owner', $owner)->andReturnSelf();
        $deleteBuilder->expects('delete')->andThrow(
            new QueryException(
                'mysql',
                'delete from cache_locks where key = ? and owner = ?',
                ['foo', $owner],
                new PDOException('Serialization failure: 1213 Deadlock', 40001)
            )
        );

        $connection->allows('transactionLevel')->andReturn(1);
        $connection->expects('table')->with('cache_locks')->andReturn($deleteBuilder);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);

        $lock = new DatabaseLock($resolver, null, 'foo', 'cache_locks', 10, $owner);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIsOrContains('Serialization failure: 1213 Deadlock');
        $lock->release();
    }
}
