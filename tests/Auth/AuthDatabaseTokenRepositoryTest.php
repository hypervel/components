<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Passwords\DatabaseTokenRepository;
use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Mockery as m;

class AuthDatabaseTokenRepositoryTest extends TestCase
{
    public function testCreateInsertsNewRecordIntoTable(): void
    {
        $repo = $this->getRepo();
        $repo->getHasher()->expects('make')->andReturn('hashed-token');
        $query = m::mock(Builder::class);
        $repo->getConnection()->expects('table')->times(2)->with('table')->andReturn($query);
        $query->expects('where')->with('email', 'email')->andReturn($query);
        $query->expects('delete');
        $query->expects('insert');
        $user = m::mock(CanResetPassword::class);
        $user->expects('getEmailForPasswordReset')->times(2)->andReturn('email');

        $results = $repo->create($user);

        $this->assertIsString($results);
        $this->assertGreaterThan(1, strlen($results));
    }

    public function testExistReturnsFalseIfNoRowFoundForUser(): void
    {
        $repo = $this->getRepo();
        $query = m::mock(Builder::class);
        $repo->getConnection()->expects('table')->with('table')->andReturn($query);
        $query->expects('where')->with('email', 'email')->andReturn($query);
        $query->expects('first')->andReturn(null);
        $user = m::mock(CanResetPassword::class);
        $user->expects('getEmailForPasswordReset')->andReturn('email');

        $this->assertFalse($repo->exists($user, 'token'));
    }

    public function testExistReturnsFalseIfRecordIsExpired(): void
    {
        $repo = $this->getRepo();
        $query = m::mock(Builder::class);
        $repo->getConnection()->expects('table')->with('table')->andReturn($query);
        $query->expects('where')->with('email', 'email')->andReturn($query);
        $date = CarbonImmutable::now()->subSeconds(300000)->toDateTimeString();
        $query->expects('first')->andReturn((object) ['created_at' => $date, 'token' => 'hashed-token']);
        $user = m::mock(CanResetPassword::class);
        $user->expects('getEmailForPasswordReset')->andReturn('email');

        $this->assertFalse($repo->exists($user, 'token'));
    }

    public function testExistReturnsTrueIfValidRecordExists(): void
    {
        $repo = $this->getRepo();
        $repo->getHasher()->expects('check')->with('token', 'hashed-token')->andReturn(true);
        $query = m::mock(Builder::class);
        $repo->getConnection()->expects('table')->with('table')->andReturn($query);
        $query->expects('where')->with('email', 'email')->andReturn($query);
        $date = CarbonImmutable::now()->subMinutes(10)->toDateTimeString();
        $query->expects('first')->andReturn((object) ['created_at' => $date, 'token' => 'hashed-token']);
        $user = m::mock(CanResetPassword::class);
        $user->expects('getEmailForPasswordReset')->andReturn('email');

        $this->assertTrue($repo->exists($user, 'token'));
    }

    public function testExistReturnsFalseIfInvalidToken(): void
    {
        $repo = $this->getRepo();
        $repo->getHasher()->expects('check')->with('wrong-token', 'hashed-token')->andReturn(false);
        $query = m::mock(Builder::class);
        $repo->getConnection()->expects('table')->with('table')->andReturn($query);
        $query->expects('where')->with('email', 'email')->andReturn($query);
        $date = CarbonImmutable::now()->subMinutes(10)->toDateTimeString();
        $query->expects('first')->andReturn((object) ['created_at' => $date, 'token' => 'hashed-token']);
        $user = m::mock(CanResetPassword::class);
        $user->expects('getEmailForPasswordReset')->andReturn('email');

        $this->assertFalse($repo->exists($user, 'wrong-token'));
    }

    public function testRecentlyCreatedReturnsFalseIfNoRowFoundForUser(): void
    {
        $repo = $this->getRepo();
        $query = m::mock(Builder::class);
        $repo->getConnection()->expects('table')->with('table')->andReturn($query);
        $query->expects('where')->with('email', 'email')->andReturn($query);
        $query->expects('first')->andReturn(null);
        $user = m::mock(CanResetPassword::class);
        $user->expects('getEmailForPasswordReset')->andReturn('email');

        $this->assertFalse($repo->recentlyCreatedToken($user));
    }

    public function testRecentlyCreatedReturnsTrueIfRecordIsRecentlyCreated(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $repo = $this->getRepo();
        $query = m::mock(Builder::class);
        $repo->getConnection()->expects('table')->with('table')->andReturn($query);
        $query->expects('where')->with('email', 'email')->andReturn($query);
        $date = $now->subSeconds(59)->toDateTimeString();
        $query->expects('first')->andReturn((object) ['created_at' => $date, 'token' => 'hashed-token']);
        $user = m::mock(CanResetPassword::class);
        $user->expects('getEmailForPasswordReset')->andReturn('email');

        $this->assertTrue($repo->recentlyCreatedToken($user));
    }

    public function testRecentlyCreatedReturnsFalseIfValidRecordExists(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $repo = $this->getRepo();
        $query = m::mock(Builder::class);
        $repo->getConnection()->expects('table')->with('table')->andReturn($query);
        $query->expects('where')->with('email', 'email')->andReturn($query);
        $date = $now->subSeconds(61)->toDateTimeString();
        $query->expects('first')->andReturn((object) ['created_at' => $date, 'token' => 'hashed-token']);
        $user = m::mock(CanResetPassword::class);
        $user->expects('getEmailForPasswordReset')->andReturn('email');

        $this->assertFalse($repo->recentlyCreatedToken($user));
    }

    public function testDeleteMethodDeletesByToken(): void
    {
        $repo = $this->getRepo();
        $query = m::mock(Builder::class);
        $repo->getConnection()->expects('table')->with('table')->andReturn($query);
        $query->expects('where')->with('email', 'email')->andReturn($query);
        $query->expects('delete');
        $user = m::mock(CanResetPassword::class);
        $user->expects('getEmailForPasswordReset')->andReturn('email');

        $repo->delete($user);
    }

    public function testDeleteExpiredMethodDeletesExpiredTokens(): void
    {
        $repo = $this->getRepo();
        $query = m::mock(Builder::class);
        $repo->getConnection()->expects('table')->with('table')->andReturn($query);
        $query->expects('where')->with('created_at', '<', m::any())->andReturn($query);
        $query->expects('delete');

        $repo->deleteExpired();
    }

    public function testResolverBackedRepositoryResolvesTheConfiguredConnectionForEveryOperation(): void
    {
        $firstConnection = m::mock(ConnectionInterface::class);
        $firstQuery = m::mock(Builder::class);
        $firstConnection->shouldReceive('table')->once()->with('table')->andReturn($firstQuery);
        $firstQuery->shouldReceive('where')->once()->with('created_at', '<', m::any())->andReturnSelf();
        $firstQuery->shouldReceive('delete')->once();

        $secondConnection = m::mock(ConnectionInterface::class);
        $secondQuery = m::mock(Builder::class);
        $secondConnection->shouldReceive('table')->once()->with('table')->andReturn($secondQuery);
        $secondQuery->shouldReceive('where')->once()->with('created_at', '<', m::any())->andReturnSelf();
        $secondQuery->shouldReceive('delete')->once();

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->twice()->with('passwords')->andReturn($firstConnection, $secondConnection);

        $repository = new DatabaseTokenRepository(
            $resolver,
            m::mock(Hasher::class),
            'table',
            'key',
            connectionName: 'passwords'
        );

        $repository->deleteExpired();
        $repository->deleteExpired();
    }

    public function testResolverBackedRepositoryUsesTheDefaultConnectionWhenNoNameIsConfigured(): void
    {
        $connection = m::mock(ConnectionInterface::class);
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->once()->with(null)->andReturn($connection);

        $repository = new DatabaseTokenRepository($resolver, m::mock(Hasher::class), 'table', 'key');

        $this->assertSame($connection, $repository->getConnection());
    }

    public function testResolverContractTakesPrecedenceForObjectsImplementingBothConnectionContracts(): void
    {
        $resolvedConnection = m::mock(ConnectionInterface::class);
        $connectionResolver = m::mock(ConnectionInterface::class . ', ' . ConnectionResolverInterface::class);
        $connectionResolver->shouldReceive('connection')->once()->with('passwords')->andReturn($resolvedConnection);

        $repository = new DatabaseTokenRepository(
            $connectionResolver,
            m::mock(Hasher::class),
            'table',
            'key',
            connectionName: 'passwords'
        );

        $this->assertSame($resolvedConnection, $repository->getConnection());
    }

    /**
     * Create a token repository with mocked dependencies.
     */
    protected function getRepo(): DatabaseTokenRepository
    {
        return new DatabaseTokenRepository(
            m::mock(ConnectionInterface::class),
            m::mock(Hasher::class),
            'table',
            'key'
        );
    }
}
