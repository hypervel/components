<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Passwords\DatabaseTokenRepository;
use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Hashing\BcryptHasher;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;

class AuthDatabaseTokenRepositoryTest extends TestCase
{
    public function testCreateInsertsNewRecordIntoTable(): void
    {
        $repo = $this->getRepo();
        $user = $this->getUser('email');
        $this->insertToken($repo, 'email', 'old-token', CarbonImmutable::now());
        $this->insertToken($repo, 'other@example.com', 'other-token', CarbonImmutable::now());

        $token = $repo->create($user);

        $this->assertIsString($token);
        $this->assertGreaterThan(1, strlen($token));
        $this->assertTrue($repo->exists($user, $token));
        $this->assertSame(1, $repo->getConnection()->table('table')->where('email', 'email')->count());
        $this->assertTrue($repo->exists($this->getUser('other@example.com'), 'other-token'));
    }

    public function testExistReturnsFalseIfNoRowFoundForUser(): void
    {
        $repo = $this->getRepo();

        $this->assertFalse($repo->exists($this->getUser('email'), 'token'));
    }

    public function testExistReturnsFalseIfRecordIsExpired(): void
    {
        $repo = $this->getRepo();
        $user = $this->getUser('email');
        $this->insertToken($repo, 'email', 'token', CarbonImmutable::now()->subSeconds(300000));

        $this->assertFalse($repo->exists($user, 'token'));
    }

    public function testExistReturnsTrueIfValidRecordExists(): void
    {
        $repo = $this->getRepo();
        $user = $this->getUser('email');
        $this->insertToken($repo, 'email', 'token', CarbonImmutable::now()->subMinutes(10));

        $this->assertTrue($repo->exists($user, 'token'));
    }

    public function testExistReturnsFalseIfInvalidToken(): void
    {
        $repo = $this->getRepo();
        $user = $this->getUser('email');
        $this->insertToken($repo, 'email', 'token', CarbonImmutable::now()->subMinutes(10));

        $this->assertFalse($repo->exists($user, 'wrong-token'));
    }

    public function testRecentlyCreatedReturnsFalseIfNoRowFoundForUser(): void
    {
        $repo = $this->getRepo();

        $this->assertFalse($repo->recentlyCreatedToken($this->getUser('email')));
    }

    public function testRecentlyCreatedReturnsTrueIfRecordIsRecentlyCreated(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $repo = $this->getRepo();
        $user = $this->getUser('email');
        $this->insertToken($repo, 'email', 'token', $now->subSeconds(59));

        $this->assertTrue($repo->recentlyCreatedToken($user));
    }

    public function testRecentlyCreatedReturnsFalseIfValidRecordExists(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $repo = $this->getRepo();
        $user = $this->getUser('email');
        $this->insertToken($repo, 'email', 'token', $now->subSeconds(61));

        $this->assertFalse($repo->recentlyCreatedToken($user));
    }

    public function testDeleteMethodDeletesByToken(): void
    {
        $repo = $this->getRepo();
        $user = $this->getUser('email');
        $this->insertToken($repo, 'email', 'token', CarbonImmutable::now());
        $this->insertToken($repo, 'other@example.com', 'other-token', CarbonImmutable::now());

        $repo->delete($user);

        $this->assertFalse($repo->exists($user, 'token'));
        $this->assertTrue($repo->exists($this->getUser('other@example.com'), 'other-token'));
    }

    public function testDeleteExpiredMethodDeletesExpiredTokens(): void
    {
        $repo = $this->getRepo();
        $this->insertToken($repo, 'expired@example.com', 'expired-token', CarbonImmutable::now()->subSeconds(3700));
        $this->insertToken($repo, 'recent@example.com', 'recent-token', CarbonImmutable::now()->subSeconds(10));

        $repo->deleteExpired();

        $rows = $repo->getConnection()->table('table')->pluck('email')->all();
        $this->assertSame(['recent@example.com'], $rows);
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
     * Create a token repository backed by an in-memory SQLite table.
     */
    protected function getRepo(): DatabaseTokenRepository
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

        $connection->getSchemaBuilder()->create('table', function (Blueprint $table): void {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        return new DatabaseTokenRepository($connection, new BcryptHasher, 'table', 'key');
    }

    /**
     * Insert a hashed token record for the given email.
     */
    protected function insertToken(DatabaseTokenRepository $repo, string $email, string $token, CarbonImmutable $createdAt): void
    {
        $repo->getConnection()->table('table')->insert([
            'email' => $email,
            'token' => $repo->getHasher()->make($token),
            'created_at' => $createdAt,
        ]);
    }

    /**
     * Create a password reset user with the given email.
     */
    protected function getUser(string $email): CanResetPassword
    {
        return new class($email) implements CanResetPassword {
            /**
             * Create a user with the given email.
             */
            public function __construct(protected string $email)
            {
            }

            /**
             * Get the e-mail address where password reset links are sent.
             */
            public function getEmailForPasswordReset(): string
            {
                return $this->email;
            }

            /**
             * Send the password reset notification.
             */
            public function sendPasswordResetNotification(string $token): void
            {
            }
        };
    }
}
