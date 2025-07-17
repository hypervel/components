<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Carbon\Carbon;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Database\Query\Builder;
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
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('insert')->once()->with([
            'key' => 'foo',
            'owner' => m::type('string'),
            'expiration' => m::type('int'),
        ])->andReturn(true);

        $lock = new DatabaseLock($resolver, 'default', 'foo', 'cache_locks', 10);
        $this->assertTrue($lock->acquire());
    }

    public function testLockCanBeAcquiredIfAlreadyOwnedBySameOwner()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('insert')->once()->andThrow(new QueryException('', '', [], new \Exception()));
        
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        
        $lock = new DatabaseLock($resolver, 'default', 'foo', 'cache_locks', 10);
        $owner = $lock->owner();
        
        $table->shouldReceive('where')->once()->andReturnUsing(function ($callback) use ($table, $owner) {
            $query = m::mock(Builder::class);
            $query->shouldReceive('where')->once()->with('owner', $owner)->andReturn($query);
            $query->shouldReceive('orWhere')->once()->with('expiration', '<=', m::type('int'))->andReturn($query);
            $callback($query);
            return $table;
        });
        $table->shouldReceive('update')->once()->with([
            'owner' => $owner,
            'expiration' => m::type('int'),
        ])->andReturn(1);

        $this->assertTrue($lock->acquire());
    }

    public function testLockCannotBeAcquiredIfAlreadyHeld()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('insert')->once()->andThrow(new QueryException('', '', [], new \Exception()));
        
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        
        $lock = new DatabaseLock($resolver, 'default', 'foo', 'cache_locks', 10);
        
        $table->shouldReceive('where')->once()->andReturnUsing(function ($callback) use ($table, $lock) {
            $query = m::mock(Builder::class);
            $query->shouldReceive('where')->once()->with('owner', $lock->owner())->andReturn($query);
            $query->shouldReceive('orWhere')->once()->with('expiration', '<=', m::type('int'))->andReturn($query);
            $callback($query);
            return $table;
        });
        $table->shouldReceive('update')->once()->andReturn(0);

        $this->assertFalse($lock->acquire());
    }

    public function testExpiredLocksAreDeletedDuringAcquisition()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('insert')->once()->andReturn(true);
        
        // Lottery hits (we'll test with lottery that always hits)
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '<=', m::type('int'))->andReturn($table);
        $table->shouldReceive('delete')->once();

        // Set lottery to always hit (1 out of 1)
        $lock = new DatabaseLock($resolver, 'default', 'foo', 'cache_locks', 10, null, [1, 1]);
        $this->assertTrue($lock->acquire());
    }

    public function testLockCanBeReleased()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        
        $lock = new DatabaseLock($resolver, 'default', 'foo', 'cache_locks', 10);
        $owner = $lock->owner();
        
        // First check if we own it
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) ['owner' => $owner]);
        
        // Then delete it
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('where')->once()->with('owner', $owner)->andReturn($table);
        $table->shouldReceive('delete')->once();

        $this->assertTrue($lock->release());
    }

    public function testLockCannotBeReleasedIfNotOwned()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) ['owner' => 'different-owner']);

        $lock = new DatabaseLock($resolver, 'default', 'foo', 'cache_locks', 10);
        $this->assertFalse($lock->release());
    }

    public function testLockCannotBeReleasedIfNotExists()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn(null);

        $lock = new DatabaseLock($resolver, 'default', 'foo', 'cache_locks', 10);
        $this->assertFalse($lock->release());
    }

    public function testLockCanBeForceReleased()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', 'foo')->andReturn($table);
        $table->shouldReceive('delete')->once();

        $lock = new DatabaseLock($resolver, 'default', 'foo', 'cache_locks', 10);
        $lock->forceRelease();
        $this->assertTrue(true);
    }

    public function testLockWithDefaultTimeout()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $currentTime = Carbon::now()->getTimestamp();
        Carbon::setTestNow(Carbon::createFromTimestamp($currentTime));
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        
        $connection->shouldReceive('table')->once()->with('cache_locks')->andReturn($table);
        $table->shouldReceive('insert')->once()->with([
            'key' => 'foo',
            'owner' => m::type('string'),
            'expiration' => $currentTime + 86400, // Default timeout
        ])->andReturn(true);

        $lock = new DatabaseLock($resolver, 'default', 'foo', 'cache_locks', 0); // 0 seconds means use default
        $this->assertTrue($lock->acquire());
    }
}