<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Carbon\Carbon;
use Exception;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Database\Query\Builder;
use Hypervel\Cache\Contracts\RefreshableLock;
use Hypervel\Cache\DatabaseLock;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class CacheDatabaseLockTest extends TestCase
{
    public function testLockCanBeAcquired()
    {
        [$lock, $table] = $this->getLock();

        $table->shouldReceive('insert')->once()->with(m::on(function ($arg) {
            return is_array($arg)
                && $arg['key'] === 'foo'
                && isset($arg['owner'])
                && is_int($arg['expiration']);
        }))->andReturn(true);

        $this->assertTrue($lock->acquire());
    }

    public function testLockCanBeAcquiredIfAlreadyOwnedBySameOwner()
    {
        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        // First attempt throws exception (key exists)
        $table->shouldReceive('insert')->once()->andThrow(new QueryException('', [], new Exception()));

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

    public function testLockCannotBeAcquiredIfAlreadyHeld()
    {
        [$lock, $table] = $this->getLock();

        // Insert fails
        $table->shouldReceive('insert')->once()->andThrow(new QueryException('', [], new Exception()));

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

    public function testExpiredLocksAreDeletedDuringAcquisition()
    {
        [$lock, $table] = $this->getLock(lockLottery: [1, 1]); // Always hit lottery

        $table->shouldReceive('insert')->once()->andReturn(true);

        // Lottery cleanup
        $table->shouldReceive('where')->once()->with('expiration', '<=', m::type('int'))->andReturn($table);
        $table->shouldReceive('delete')->once();

        $this->assertTrue($lock->acquire());
    }

    public function testLockCanBeReleased()
    {
        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        // Check ownership
        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) ['owner' => $owner]);

        // Delete
        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('delete')->once();

        $this->assertTrue($lock->release());
    }

    public function testLockCannotBeReleasedIfNotOwned()
    {
        [$lock, $table] = $this->getLock();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) ['owner' => 'different-owner']);

        $this->assertFalse($lock->release());
    }

    public function testLockCannotBeReleasedIfNotExists()
    {
        [$lock, $table] = $this->getLock();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn(null);

        $this->assertFalse($lock->release());
    }

    public function testLockCanBeForceReleased()
    {
        [$lock, $table] = $this->getLock();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('delete')->once();

        $lock->forceRelease();
        $this->assertTrue(true); // Just verify no exceptions
    }

    public function testLockWithDefaultTimeout()
    {
        Carbon::setTestNow($now = Carbon::now());

        [$lock, $table] = $this->getLock(seconds: 0);

        $table->shouldReceive('insert')->once()->with(m::on(function ($arg) use ($now) {
            return is_array($arg)
                && $arg['key'] === 'foo'
                && isset($arg['owner'])
                && $arg['expiration'] === $now->getTimestamp() + 86400; // Default timeout
        }))->andReturn(true);

        $this->assertTrue($lock->acquire());
    }

    public function testLockImplementsRefreshableLock()
    {
        [$lock] = $this->getLock();

        $this->assertInstanceOf(RefreshableLock::class, $lock);
    }

    public function testRefreshExtendsLockExpiration()
    {
        Carbon::setTestNow($now = Carbon::now());

        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('update')->once()->with(m::on(function ($arg) use ($now) {
            return is_array($arg)
                && $arg['expiration'] === $now->getTimestamp() + 10;
        }))->andReturn(1);

        $this->assertTrue($lock->refresh());
    }

    public function testRefreshWithCustomTtl()
    {
        Carbon::setTestNow($now = Carbon::now());

        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('update')->once()->with(m::on(function ($arg) use ($now) {
            return is_array($arg)
                && $arg['expiration'] === $now->getTimestamp() + 30;
        }))->andReturn(1);

        $this->assertTrue($lock->refresh(30));
    }

    public function testRefreshReturnsFalseWhenNotOwned()
    {
        [$lock, $table] = $this->getLock();
        $owner = $lock->owner();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('update')->once()->andReturn(0);

        $this->assertFalse($lock->refresh());
    }

    public function testRefreshWithZeroSecondsUsesDefaultTimeout()
    {
        Carbon::setTestNow($now = Carbon::now());

        [$lock, $table] = $this->getLock(seconds: 0);
        $owner = $lock->owner();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('update')->once()->with(m::on(function ($arg) use ($now) {
            return is_array($arg)
                && $arg['expiration'] === $now->getTimestamp() + 86400; // Default timeout
        }))->andReturn(1);

        $this->assertTrue($lock->refresh());
    }

    public function testGetRemainingLifetimeReturnsSeconds()
    {
        Carbon::setTestNow($now = Carbon::now());

        [$lock, $table] = $this->getLock();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) [
            'expiration' => $now->getTimestamp() + 5,
        ]);

        $this->assertSame(5.0, $lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullWhenLockDoesNotExist()
    {
        [$lock, $table] = $this->getLock();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn(null);

        $this->assertNull($lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullWhenExpired()
    {
        Carbon::setTestNow($now = Carbon::now());

        [$lock, $table] = $this->getLock();

        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) [
            'expiration' => $now->getTimestamp() - 1, // Already expired
        ]);

        $this->assertNull($lock->getRemainingLifetime());
    }

    /**
     * Get a DatabaseLock instance with mocked dependencies.
     */
    protected function getLock(int $seconds = 10, array $lockLottery = [0, 1]): array
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);

        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->with('cache_locks')->andReturn($table);

        $lock = new DatabaseLock(
            $resolver,
            'default',
            'foo',
            'cache_locks',
            $seconds,
            null,
            $lockLottery
        );

        return [$lock, $table, $connection, $resolver];
    }
}
