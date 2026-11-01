<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Closure;
use Exception;
use Hypervel\Cache\DatabaseLock;
use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\QueryException;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;

class CacheDatabaseLockTest extends TestCase
{
    public function testLockCanBeAcquired(): void
    {
        [$lock, $table] = $this->getLock();

        $table->shouldReceive('insertOrIgnore')->once()->with(m::on(function ($arg) {
            return is_array($arg)
                && $arg['key'] === 'foo'
                && isset($arg['owner'])
                && is_int($arg['expiration']);
        }))->andReturn(1);

        $this->assertTrue($lock->acquire());
    }

    public function testOwnershipAndLifetimeReadTheWriterInsteadOfAStaleReplica(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1000));
        $writer = new PDO('sqlite::memory:');
        $reader = new PDO('sqlite::memory:');
        foreach ([$writer, $reader] as $pdo) {
            $pdo->exec('create table cache_locks (key text primary key, owner text, expiration integer)');
        }
        $writer->exec("insert into cache_locks values ('key', 'fresh', 1060)");
        $reader->exec("insert into cache_locks values ('key', 'stale', 1010)");
        $connection = new SQLiteConnection($writer, ':memory:');
        $connection->setReadPdo($reader);
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);
        $lock = new DatabaseLock($resolver, null, 'key', 'cache_locks', 60, 'fresh', null);

        $this->assertTrue($lock->isOwnedByCurrentProcess());
        $this->assertSame(60.0, $lock->getRemainingLifetime());
    }

    public function testFractionalSecondAcquireAndRefreshNeverExpireBeforeRequestedDeadline(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        [$lock, $table] = $this->getLock(seconds: 1);
        $owner = $lock->owner();

        $table->shouldReceive('insertOrIgnore')->once()->with([
            'key' => 'foo',
            'owner' => $owner,
            'expiration' => 1002,
        ])->andReturn(1);

        $this->assertTrue($lock->acquire());

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '>', 1000)->andReturn($table);
        $table->shouldReceive('update')->once()->with(['expiration' => 1002])->andReturn(1);

        $this->assertTrue($lock->refresh());
    }

    public function testLockCanBeAcquiredIfAlreadyOwnedBySameOwner(): void
    {
        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        // First attempt inserts nothing because the key exists.
        $table->shouldReceive('insertOrIgnore')->once()->andReturn(0);

        // So it tries to update
        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->andReturnUsing(function ($callback) use ($table, $owner) {
            $query = m::mock(Builder::class);
            $query->shouldReceive('where')->once()->with('owner', $owner)->andReturn($query);
            $query->shouldReceive('orWhere')->once()->with('expiration', '<=', m::type('int'))->andReturn($query);
            $callback($query);
            return $table;
        });
        $table->shouldReceive('update')->once()->with(m::on(function ($arg) use ($owner) {
            return is_array($arg)
                && $arg['owner'] === $owner
                && is_int($arg['expiration']);
        }))->andReturn(1);

        $this->assertTrue($lock->acquire());
    }

    public function testLockCannotBeAcquiredIfAlreadyHeld(): void
    {
        [$lock, $table] = $this->getLock();

        // Insert finds the existing key.
        $table->shouldReceive('insertOrIgnore')->once()->andReturn(0);

        // Update fails too (someone else owns it)
        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->andReturnUsing(function ($callback) use ($table, $lock) {
            $query = m::mock(Builder::class);
            $query->shouldReceive('where')->once()->with('owner', $lock->owner())->andReturn($query);
            $query->shouldReceive('orWhere')->once()->with('expiration', '<=', m::type('int'))->andReturn($query);

            // The callback should return the query
            $result = $callback($query);

            // Verify the callback returned the query as expected
            $this->assertSame($query, $result);

            return $table;
        });
        $table->shouldReceive('update')->once()->andReturn(0);

        $this->assertFalse($lock->acquire());
    }

    public function testInsertFailureOtherThanAnExistingLockPropagatesWithoutTakingOver(): void
    {
        [$lock, $table] = $this->getLock();
        $failure = new QueryException('default', 'insert into "cache_locks"', [], new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));

        $table->shouldReceive('insertOrIgnore')->once()->andThrow($failure);
        $table->shouldNotReceive('update');

        try {
            $lock->acquire();
            $this->fail('The insert failure was not thrown.');
        } catch (QueryException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testSqliteLockHeldByAnotherOwnerIsRefusedWithoutWriting(): void
    {
        // The lottery always prunes, so any write, including pruning, fails the unexpected call.
        [$lock] = $this->getLock(lockLottery: [1, 1], driver: 'sqlite', tables: [$this->liveOwnerRead(static fn (): string => 'other-owner')]);

        $this->assertFalse($lock->acquire());
    }

    public function testSqliteLockIsInsertedWhenNoLiveLockExists(): void
    {
        $insert = m::mock(Builder::class);
        $insert->shouldReceive('insertOrIgnore')->once()->andReturn(1);

        [$lock] = $this->getLock(driver: 'sqlite', tables: [$this->liveOwnerRead(static fn (): null => null), $insert]);

        $this->assertTrue($lock->acquire());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testSqliteExpiredOrOwnLockIsTakenOverByTheConditionalUpdate(bool $own): void
    {
        $lock = null;
        // An expired row is not live, so the read finds no owner and the insert meets the existing key.
        $read = $this->liveOwnerRead(static function () use (&$lock, $own): ?string {
            return $own ? $lock->owner() : null;
        });
        $insert = m::mock(Builder::class);
        $insert->shouldReceive('insertOrIgnore')->once()->andReturn(0);
        $update = m::mock(Builder::class);
        $update->shouldReceive('where')->once()->with('key', 'foo')->andReturnSelf();
        $update->shouldReceive('where')->once()->with(m::type(Closure::class))->andReturnSelf();
        $update->shouldReceive('update')->once()->andReturn(1);

        [$lock] = $this->getLock(driver: 'sqlite', tables: [$read, $insert, $update]);

        $this->assertTrue($lock->acquire());
    }

    #[TestWith(['mysql', true])]
    #[TestWith(['mysql', false])]
    #[TestWith(['mariadb', true])]
    public function testATakeoverThatChangedNoRowIsConfirmedWithACurrentReadWhereOnlyChangedRowsCount(string $driver, bool $owned): void
    {
        $insert = m::mock(Builder::class);
        $insert->shouldReceive('insert')->once()->andThrow(new UniqueConstraintViolationException('', '', [], new Exception));
        $update = m::mock(Builder::class);
        $update->shouldReceive('where')->once()->with('key', 'foo')->andReturnSelf();
        $update->shouldReceive('where')->once()->with(m::type(Closure::class))->andReturnSelf();
        $update->shouldReceive('update')->once()->andReturn(0);
        $read = m::mock(Builder::class);

        // A lock object that never acquired: a lost-connection retry can still meet the row its first insert committed.
        [$lock] = $this->getLock(driver: $driver, tables: [$insert, $update, $read]);
        $this->expectLiveOwnerCurrentRead($read, $lock->owner(), $owned);

        $this->assertSame($owned, $lock->acquire());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testARefreshThatChangedNoRowIsConfirmedWithACurrentReadWhereOnlyChangedRowsCount(bool $owned): void
    {
        $update = m::mock(Builder::class);
        $update->shouldReceive('where')->once()->with('key', 'foo')->andReturnSelf();
        $update->shouldReceive('where')->once()->with('owner', m::type('string'))->andReturnSelf();
        $update->shouldReceive('where')->once()->with('expiration', '>', m::type('int'))->andReturnSelf();
        $update->shouldReceive('update')->once()->andReturn(0);
        $read = m::mock(Builder::class);

        [$lock] = $this->getLock(driver: 'mysql', tables: [$update, $read]);
        $this->expectLiveOwnerCurrentRead($read, $lock->owner(), $owned);

        $this->assertSame($owned, $lock->refresh());
    }

    public function testExpiredLocksAreDeletedDuringAcquisition(): void
    {
        [$lock, $table] = $this->getLock(lockLottery: [1, 1]); // Always hit lottery

        $table->shouldReceive('insertOrIgnore')->once()->andReturn(1);

        // Lottery cleanup
        $table->shouldReceive('where')->once()->with('expiration', '<=', m::type('int'))->andReturn($table);
        $table->shouldReceive('delete')->once();

        $this->assertTrue($lock->acquire());
    }

    #[TestWith([null])]
    #[TestWith([[]])]
    public function testLockCanBeAcquiredWithoutAutomaticPruning(?array $lottery): void
    {
        [$lock, $table] = $this->getLock(lockLottery: $lottery);

        $table->shouldReceive('insertOrIgnore')->once()->andReturn(1);

        $this->assertTrue($lock->acquire());
    }

    public function testLockCanBeReleased(): void
    {
        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('delete')->once()->andReturn(1);

        $this->assertTrue($lock->release());
    }

    public function testReleaseReturnsFalseWhenNoOwnedRowMatches(): void
    {
        [$lock, $table] = $this->getLock();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $lock->owner())->andReturn($table);
        $table->shouldReceive('delete')->once()->andReturn(0);

        $this->assertFalse($lock->release());
    }

    public function testExpiredLockIsNotLockedOrOwned(): void
    {
        [$lock, $table] = $this->getLock();

        $table->shouldReceive('where')->twice()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->twice()->with('expiration', '>', m::type('int'))->andReturn($table);
        $table->shouldReceive('first')->twice()->andReturn(null);

        $this->assertFalse($lock->isLocked());
        $this->assertFalse($lock->isOwnedByCurrentProcess());
    }

    public function testLockCanBeForceReleased(): void
    {
        [$lock, $table] = $this->getLock();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('delete')->once();

        $lock->forceRelease();
        $this->assertTrue(true); // Just verify no exceptions
    }

    public function testLockWithDefaultTimeout(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now()->startOfSecond());

        [$lock, $table] = $this->getLock(seconds: 0);

        $table->shouldReceive('insertOrIgnore')->once()->with(m::on(function ($arg) use ($now) {
            return is_array($arg)
                && $arg['key'] === 'foo'
                && isset($arg['owner'])
                && $arg['expiration'] === $now->getTimestamp() + 86400; // Default timeout
        }))->andReturn(1);

        $this->assertTrue($lock->acquire());
    }

    public function testLockImplementsRefreshableLock(): void
    {
        [$lock] = $this->getLock();

        $this->assertInstanceOf(RefreshableLock::class, $lock);
    }

    public function testRefreshExtendsLockExpiration(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now()->startOfSecond());

        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '>', m::type('int'))->andReturn($table);
        $table->shouldReceive('update')->once()->with(m::on(function ($arg) use ($now) {
            return is_array($arg)
                && $arg['expiration'] === $now->getTimestamp() + 10;
        }))->andReturn(1);

        $this->assertTrue($lock->refresh());
    }

    public function testRefreshWithCustomTtl(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now()->startOfSecond());

        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '>', m::type('int'))->andReturn($table);
        $table->shouldReceive('update')->once()->with(m::on(function ($arg) use ($now) {
            return is_array($arg)
                && $arg['expiration'] === $now->getTimestamp() + 30;
        }))->andReturn(1);

        $this->assertTrue($lock->refresh(30));
    }

    public function testRefreshReturnsFalseWhenNotOwned(): void
    {
        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '>', m::type('int'))->andReturn($table);
        $table->shouldReceive('update')->once()->andReturn(0);

        $this->assertFalse($lock->refresh());
    }

    public function testRefreshOnDefaultTimeoutLockReappliesDefaultTimeout(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now()->startOfSecond());

        [$lock, $table] = $this->getLock(seconds: 0);
        $owner = $lock->owner();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '>', m::type('int'))->andReturn($table);
        $table->shouldReceive('update')->once()->with(m::on(function ($arg) use ($now) {
            return is_array($arg)
                && $arg['expiration'] === $now->getTimestamp() + 86400;
        }))->andReturn(1);

        $this->assertTrue($lock->refresh());
    }

    public function testRefreshReturnsFalseWhenExpired(): void
    {
        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '>', m::type('int'))->andReturn($table);
        $table->shouldReceive('update')->once()->andReturn(0);

        $this->assertFalse($lock->refresh());
    }

    public function testRefreshWithExplicitZeroThrowsException(): void
    {
        [$lock] = $this->getLock(seconds: 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Refresh requires a positive TTL.');

        $lock->refresh(0);
    }

    public function testRefreshWithNegativeSecondsThrowsException(): void
    {
        [$lock] = $this->getLock(seconds: 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Refresh requires a positive TTL.');

        $lock->refresh(-5);
    }

    public function testGetRemainingLifetimeReturnsSeconds(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        [$lock, $table] = $this->getLock();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '>', $now->getTimestamp())->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) [
            'expiration' => $now->getTimestamp() + 5,
        ]);

        $this->assertSame(5.0, $lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullWhenLockDoesNotExist(): void
    {
        [$lock, $table] = $this->getLock();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '>', m::type('int'))->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn(null);

        $this->assertNull($lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullWhenExpired(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        [$lock, $table] = $this->getLock();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '>', $now->getTimestamp())->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn(null);

        $this->assertNull($lock->getRemainingLifetime());
    }

    public function testGetConnectionNameReturnsConnectionName(): void
    {
        [$lock,, $connection] = $this->getLock();

        $connection->shouldReceive('getName')->once()->andReturn('default');

        $this->assertSame('default', $lock->getConnectionName());
    }

    public function testGetConnectionNameCanReturnNull(): void
    {
        [$lock,, $connection] = $this->getLock(connectionName: null);

        $connection->shouldReceive('getName')->once()->andReturn(null);

        $this->assertNull($lock->getConnectionName());
    }

    /**
     * Get a DatabaseLock instance with mocked dependencies.
     *
     * @param list<Builder> $tables the builders the connection returns in turn, or one shared builder when empty
     */
    protected function getLock(int $seconds = 10, ?array $lockLottery = [0, 1], ?string $connectionName = 'default', string $driver = 'pgsql', array $tables = []): array
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        $table->shouldReceive('useWritePdo')->andReturnSelf()->byDefault();

        $resolver->shouldReceive('connection')->with($connectionName)->andReturn($connection);
        $connection->shouldReceive('getDriverName')->andReturn($driver);
        $connection->shouldReceive('table')->with('cache_locks')->andReturn(...($tables === [] ? [$table] : $tables));

        $lock = new DatabaseLock(
            $resolver,
            $connectionName,
            'foo',
            'cache_locks',
            $seconds,
            null,
            $lockLottery
        );

        return [$lock, $table, $connection, $resolver];
    }

    /**
     * Expect the locking read of whether an owner holds the live lock.
     */
    protected function expectLiveOwnerCurrentRead(Builder $read, string $owner, bool $owned): void
    {
        $read->shouldReceive('sharedLock')->once()->andReturnSelf();
        $read->shouldReceive('where')->once()->with('key', 'foo')->andReturnSelf();
        $read->shouldReceive('where')->once()->with('owner', $owner)->andReturnSelf();
        $read->shouldReceive('where')->once()->with('expiration', '>', m::type('int'))->andReturnSelf();
        $read->shouldReceive('exists')->once()->andReturn($owned);
    }

    /**
     * Get a builder for the SQLite read of the lock's live owner, on the write connection.
     *
     * @param Closure(): ?string $owner
     */
    protected function liveOwnerRead(Closure $owner): Builder
    {
        $read = m::mock(Builder::class);
        $read->shouldReceive('useWritePdo')->once()->andReturnSelf();
        $read->shouldReceive('where')->once()->with('key', 'foo')->andReturnSelf();
        $read->shouldReceive('where')->once()->with('expiration', '>', m::type('int'))->andReturnSelf();
        $read->shouldReceive('value')->once()->with('owner')->andReturnUsing($owner);

        return $read;
    }
}
