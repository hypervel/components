<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Closure;
use Exception;
use Hypervel\Cache\DatabaseLock;
use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\QueryException;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;

class CacheDatabaseLockTest extends TestCase
{
    use VerifiesDoubles;

    public function testLockCanBeAcquired(): void
    {
        [$lock, $table] = $this->getLock();

        $table->expects('insertOrIgnore')->with(Argument::satisfies(function ($arg) {
            return is_array($arg)
                && $arg['key'] === 'foo'
                && isset($arg['owner'])
                && is_int($arg['expiration']);
        }))->returns(1);

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
        $resolver = Double::for(ConnectionResolverInterface::class);
        $resolver->allows('connection')->returns($connection);
        $lock = new DatabaseLock($resolver, null, 'key', 'cache_locks', 60, 'fresh', null);

        $this->assertTrue($lock->isOwnedByCurrentProcess());
        $this->assertSame(60.0, $lock->getRemainingLifetime());
    }

    public function testFractionalSecondAcquireAndRefreshNeverExpireBeforeRequestedDeadline(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        [$lock, $table] = $this->getLock(seconds: 1);
        $owner = $lock->owner();

        $table->expects('insertOrIgnore')->with([
            'key' => 'foo',
            'owner' => $owner,
            'expiration' => 1002,
        ])->returns(1);

        $this->assertTrue($lock->acquire());

        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with('owner', $owner)->returns($table);
        $table->expects('where')->with('expiration', '>', 1000)->returns($table);
        $table->expects('update')->with(['expiration' => 1002])->returns(1);

        $this->assertTrue($lock->refresh());
    }

    public function testLockCanBeAcquiredIfAlreadyOwnedBySameOwner(): void
    {
        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        // First attempt inserts nothing because the key exists.
        $table->expects('insertOrIgnore')->returns(0);

        // So it tries to update
        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with(Argument::type(Closure::class))->resolves(function ($callback) use ($table, $owner) {
            $query = Double::for(Builder::class);
            $query->expects('where')->with('owner', $owner)->returns($query);
            $query->expects('orWhere')->with('expiration', '<=', Argument::type('int'))->returns($query);
            $callback($query);
            return $table;
        });
        $table->expects('update')->with(Argument::satisfies(function ($arg) use ($owner) {
            return is_array($arg)
                && $arg['owner'] === $owner
                && is_int($arg['expiration']);
        }))->returns(1);

        $this->assertTrue($lock->acquire());
    }

    public function testLockCannotBeAcquiredIfAlreadyHeld(): void
    {
        [$lock, $table] = $this->getLock();

        // Insert finds the existing key.
        $table->expects('insertOrIgnore')->returns(0);

        // Update fails too (someone else owns it)
        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with(Argument::type(Closure::class))->resolves(function ($callback) use ($table, $lock) {
            $query = Double::for(Builder::class);
            $query->expects('where')->with('owner', $lock->owner())->returns($query);
            $query->expects('orWhere')->with('expiration', '<=', Argument::type('int'))->returns($query);

            // The callback should return the query
            $result = $callback($query);

            // Verify the callback returned the query as expected
            $this->assertSame($query, $result);

            return $table;
        });
        $table->expects('update')->returns(0);

        $this->assertFalse($lock->acquire());
    }

    public function testInsertFailureOtherThanAnExistingLockPropagatesWithoutTakingOver(): void
    {
        [$lock, $table] = $this->getLock();
        $failure = new QueryException('default', 'insert into "cache_locks"', [], new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));

        $table->expects('insertOrIgnore')->throws($failure);
        $table->expects('update')->never();

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
        [$lock] = $this->getLock(lockLottery: [1, 1], driver: 'sqlite', tables: [$this->liveOwnerRead(static fn (): string => 'other-owner')->strict()]);

        $this->assertFalse($lock->acquire());
    }

    public function testSqliteLockIsInsertedWhenNoLiveLockExists(): void
    {
        $insert = Double::for(Builder::class);
        $insert->expects('insertOrIgnore')->returns(1);

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
        $insert = Double::for(Builder::class);
        $insert->expects('insertOrIgnore')->returns(0);
        $update = Double::for(Builder::class);
        $update->expects('where')->with('key', 'foo');
        $update->expects('where')->with(Argument::type(Closure::class));
        $update->expects('update')->returns(1);

        [$lock] = $this->getLock(driver: 'sqlite', tables: [$read, $insert, $update]);

        $this->assertTrue($lock->acquire());
    }

    #[TestWith(['mysql', true])]
    #[TestWith(['mysql', false])]
    #[TestWith(['mariadb', true])]
    public function testATakeoverThatChangedNoRowIsConfirmedWithACurrentReadWhereOnlyChangedRowsCount(string $driver, bool $owned): void
    {
        $insert = Double::for(Builder::class);
        $insert->expects('insert')->throws(new UniqueConstraintViolationException('', '', [], new Exception));
        $update = Double::for(Builder::class);
        $update->expects('where')->with('key', 'foo');
        $update->expects('where')->with(Argument::type(Closure::class));
        $update->expects('update')->returns(0);
        $read = Double::for(Builder::class);

        // A lock object that never acquired: a lost-connection retry can still meet the row its first insert committed.
        [$lock] = $this->getLock(driver: $driver, tables: [$insert, $update, $read]);
        $this->expectLiveOwnerCurrentRead($read, $lock->owner(), $owned);

        $this->assertSame($owned, $lock->acquire());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testARefreshThatChangedNoRowIsConfirmedWithACurrentReadWhereOnlyChangedRowsCount(bool $owned): void
    {
        $update = Double::for(Builder::class);
        $update->expects('where')->with('key', 'foo');
        $update->expects('where')->with('owner', Argument::type('string'));
        $update->expects('where')->with('expiration', '>', Argument::type('int'));
        $update->expects('update')->returns(0);
        $read = Double::for(Builder::class);

        [$lock] = $this->getLock(driver: 'mysql', tables: [$update, $read]);
        $this->expectLiveOwnerCurrentRead($read, $lock->owner(), $owned);

        $this->assertSame($owned, $lock->refresh());
    }

    public function testExpiredLocksAreDeletedDuringAcquisition(): void
    {
        [$lock, $table] = $this->getLock(lockLottery: [1, 1]); // Always hit lottery

        $table->expects('insertOrIgnore')->returns(1);

        // Lottery cleanup
        $table->expects('where')->with('expiration', '<=', Argument::type('int'))->returns($table);
        $table->expects('delete');

        $this->assertTrue($lock->acquire());
    }

    #[TestWith([null])]
    #[TestWith([[]])]
    public function testLockCanBeAcquiredWithoutAutomaticPruning(?array $lottery): void
    {
        [$lock, $table] = $this->getLock(lockLottery: $lottery);

        $table->strict();
        $table->expects('insertOrIgnore')->returns(1);

        $this->assertTrue($lock->acquire());
    }

    public function testLockCanBeReleased(): void
    {
        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with('owner', $owner)->returns($table);
        $table->expects('delete')->returns(1);

        $this->assertTrue($lock->release());
    }

    public function testReleaseReturnsFalseWhenNoOwnedRowMatches(): void
    {
        [$lock, $table] = $this->getLock();

        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with('owner', $lock->owner())->returns($table);
        $table->expects('delete')->returns(0);

        $this->assertFalse($lock->release());
    }

    public function testExpiredLockIsNotLockedOrOwned(): void
    {
        [$lock, $table] = $this->getLock();

        $table->expects('where')->times(2)->with('key', 'foo')->returns($table);
        $table->expects('where')->times(2)->with('expiration', '>', Argument::type('int'))->returns($table);
        $table->expects('first')->times(2)->returns(null);

        $this->assertFalse($lock->isLocked());
        $this->assertFalse($lock->isOwnedByCurrentProcess());
    }

    public function testLockCanBeForceReleased(): void
    {
        [$lock, $table] = $this->getLock();

        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('delete');

        $lock->forceRelease();
    }

    public function testLockWithDefaultTimeout(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now()->startOfSecond());

        [$lock, $table] = $this->getLock(seconds: 0);

        $table->expects('insertOrIgnore')->with(Argument::satisfies(function ($arg) use ($now) {
            return is_array($arg)
                && $arg['key'] === 'foo'
                && isset($arg['owner'])
                && $arg['expiration'] === $now->getTimestamp() + 86400; // Default timeout
        }))->returns(1);

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

        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with('owner', $owner)->returns($table);
        $table->expects('where')->with('expiration', '>', Argument::type('int'))->returns($table);
        $table->expects('update')->with(Argument::satisfies(function ($arg) use ($now) {
            return is_array($arg)
                && $arg['expiration'] === $now->getTimestamp() + 10;
        }))->returns(1);

        $this->assertTrue($lock->refresh());
    }

    public function testRefreshWithCustomTtl(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now()->startOfSecond());

        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with('owner', $owner)->returns($table);
        $table->expects('where')->with('expiration', '>', Argument::type('int'))->returns($table);
        $table->expects('update')->with(Argument::satisfies(function ($arg) use ($now) {
            return is_array($arg)
                && $arg['expiration'] === $now->getTimestamp() + 30;
        }))->returns(1);

        $this->assertTrue($lock->refresh(30));
    }

    public function testRefreshReturnsFalseWhenNotOwned(): void
    {
        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with('owner', $owner)->returns($table);
        $table->expects('where')->with('expiration', '>', Argument::type('int'))->returns($table);
        $table->expects('update')->returns(0);

        $this->assertFalse($lock->refresh());
    }

    public function testRefreshOnDefaultTimeoutLockReappliesDefaultTimeout(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now()->startOfSecond());

        [$lock, $table] = $this->getLock(seconds: 0);
        $owner = $lock->owner();

        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with('owner', $owner)->returns($table);
        $table->expects('where')->with('expiration', '>', Argument::type('int'))->returns($table);
        $table->expects('update')->with(Argument::satisfies(function ($arg) use ($now) {
            return is_array($arg)
                && $arg['expiration'] === $now->getTimestamp() + 86400;
        }))->returns(1);

        $this->assertTrue($lock->refresh());
    }

    public function testRefreshReturnsFalseWhenExpired(): void
    {
        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with('owner', $owner)->returns($table);
        $table->expects('where')->with('expiration', '>', Argument::type('int'))->returns($table);
        $table->expects('update')->returns(0);

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

        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with('expiration', '>', $now->getTimestamp())->returns($table);
        $table->expects('first')->returns((object) [
            'expiration' => $now->getTimestamp() + 5,
        ]);

        $this->assertSame(5.0, $lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullWhenLockDoesNotExist(): void
    {
        [$lock, $table] = $this->getLock();

        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with('expiration', '>', Argument::type('int'))->returns($table);
        $table->expects('first')->returns(null);

        $this->assertNull($lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullWhenExpired(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        [$lock, $table] = $this->getLock();

        $table->expects('where')->with('key', 'foo')->returns($table);
        $table->expects('where')->with('expiration', '>', $now->getTimestamp())->returns($table);
        $table->expects('first')->returns(null);

        $this->assertNull($lock->getRemainingLifetime());
    }

    public function testGetConnectionNameReturnsConnectionName(): void
    {
        [$lock,, $connection] = $this->getLock();

        $connection->expects('getName')->returns('default');

        $this->assertSame('default', $lock->getConnectionName());
    }

    public function testGetConnectionNameCanReturnNull(): void
    {
        [$lock,, $connection] = $this->getLock(connectionName: null);

        $connection->expects('getName')->returns(null);

        $this->assertNull($lock->getConnectionName());
    }

    /**
     * Get a DatabaseLock instance with mocked dependencies.
     *
     * @param list<Builder> $tables the builders the connection returns in turn, or one shared builder when empty
     */
    protected function getLock(int $seconds = 10, ?array $lockLottery = [0, 1], ?string $connectionName = 'default', string $driver = 'pgsql', array $tables = []): array
    {
        $resolver = Double::for(ConnectionResolverInterface::class);
        $connection = Double::for(Connection::class);
        $table = Double::for(Builder::class);
        $table->allows('useWritePdo');

        $resolver->allows('connection')->with($connectionName)->returns($connection);
        $connection->allows('getDriverName')->returns($driver);
        $connection->allows('table')->with('cache_locks')->returns(...($tables === [] ? [$table] : $tables));

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
        $read->expects('sharedLock');
        $read->expects('where')->with('key', 'foo');
        $read->expects('where')->with('owner', $owner);
        $read->expects('where')->with('expiration', '>', Argument::type('int'));
        $read->expects('exists')->returns($owned);
    }

    /**
     * Get a builder for the SQLite read of the lock's live owner, on the write connection.
     *
     * @param Closure(): ?string $owner
     */
    protected function liveOwnerRead(Closure $owner): Builder&DoubleInterface
    {
        $read = Double::for(Builder::class);
        $read->expects('useWritePdo');
        $read->expects('where')->with('key', 'foo');
        $read->expects('where')->with('expiration', '>', Argument::type('int'));
        $read->expects('value')->with('owner')->resolves($owner);

        return $read;
    }
}
