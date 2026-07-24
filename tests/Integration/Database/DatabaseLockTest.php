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
        $this->app['config']->set('cache.stores.database.lock_connection', 'test');
        $this->app['config']->set('database.connections.test', $this->app['config']->get('database.connections.testing'));

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
    }

    public function testLockCanBeRefreshed(): void
    {
        $lock = Cache::driver('database')->lock('foo', 10);
        $this->assertTrue($lock->get());

        $this->assertTrue($lock->refresh(20));
        $this->assertFalse(Cache::driver('database')->lock('foo', 10)->get());

        $lock->release();
    }

    public function testLockCannotBeRefreshedByAnotherOwner(): void
    {
        $firstLock = Cache::driver('database')->lock('foo', 10);
        $this->assertTrue($firstLock->get());

        $secondLock = Cache::store('database')->restoreLock('foo', 'other_owner');

        $this->assertFalse($secondLock->refresh(20));
        $this->assertTrue($firstLock->refresh(20));

        $firstLock->release();
    }

    public function testExpiredLockCannotBeRefreshedByPreviousOwner(): void
    {
        $lock = Cache::driver('database')->lock('foo', 10);
        $this->assertTrue($lock->get());

        DB::table('cache_locks')->update(['expiration' => CarbonImmutable::now()->subDay()->getTimestamp()]);

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

        $insertBuilder->shouldReceive('insert')->once()->andReturn(true);

        $deleteBuilder->shouldReceive('where')->with('expiration', '<=', m::any())->once()->andReturnSelf();
        $deleteBuilder->shouldReceive('delete')->once()->andThrow(
            new QueryException(
                'mysql',
                'delete from cache_locks where expiration <= ?',
                [],
                new PDOException($message, $code)
            )
        );

        $connection->shouldReceive('table')->with('cache_locks')->andReturn($insertBuilder, $deleteBuilder);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);

        $lock = new DatabaseLock($resolver, null, 'foo', 'cache_locks', 0, lottery: [1, 1]);

        if ($hasConcurrencyError) {
            $this->assertTrue($lock->acquire());
        } else {
            $this->expectException(QueryException::class);
            $this->assertFalse($lock->acquire());
        }
    }

    #[TestWith(['Serialization failure: 1213 Deadlock', 40001, true])]
    #[TestWith(['Table does not exist', 1146, false])]
    public function testReleaseIgnoresConcurrencyException(string $message, int $code, bool $hasConcurrencyError): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(Connection::class);
        $ownerBuilder = m::mock(Builder::class);
        $deleteBuilder = m::mock(Builder::class);

        $owner = 'owner-123';

        $ownerBuilder->shouldReceive('where')->with('key', 'foo')->once()->andReturnSelf();
        $ownerBuilder->shouldReceive('where')->with('expiration', '>', m::type('int'))->once()->andReturnSelf();
        $ownerBuilder->shouldReceive('first')->once()->andReturn((object) ['owner' => $owner]);

        $deleteBuilder->shouldReceive('where')->with('key', 'foo')->once()->andReturnSelf();
        $deleteBuilder->shouldReceive('where')->with('owner', $owner)->once()->andReturnSelf();
        $deleteBuilder->shouldReceive('delete')->once()->andThrow(
            new QueryException(
                'mysql',
                'delete from cache_locks where key = ? and owner = ?',
                ['foo', $owner],
                new PDOException($message, $code)
            )
        );

        $connection->shouldReceive('table')->with('cache_locks')->andReturn($ownerBuilder, $deleteBuilder);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);

        $lock = new DatabaseLock($resolver, null, 'foo', 'cache_locks', 10, $owner);

        if ($hasConcurrencyError) {
            $this->assertTrue($lock->release());
        } else {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessage($message);
            $lock->release();
        }
    }
}
