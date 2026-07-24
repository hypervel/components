<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Pool\DbPool;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Engine\Coroutine;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use RuntimeException;

use function Hypervel\Coroutine\run;

/**
 * Regression tests for ConnectionResolver::setDefaultConnection() using
 * CoroutineContext. Mirrors the DatabaseManager tests since both
 * implementations share the same Context key and semantics.
 */
class ConnectionResolverTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected function tearDown(): void
    {
        CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function testSetDefaultConnectionWritesToCoroutineContext(): void
    {
        $resolver = $this->makeResolver('pgsql');

        $resolver->setDefaultConnection('reporting');

        $this->assertSame(
            'reporting',
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
        );
    }

    public function testSetDefaultConnectionWithNullClearsContextOverride(): void
    {
        $resolver = $this->makeResolver('pgsql');

        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'reporting');

        $resolver->setDefaultConnection(null);

        $this->assertNull(
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
        );
    }

    public function testGetDefaultConnectionFallsBackToConfigCapturedAtConstruction(): void
    {
        $resolver = $this->makeResolver('pgsql');

        // No override set — should fall back to the config-captured default
        $this->assertSame('pgsql', $resolver->getDefaultConnection());

        // Override, then clear — should fall back again
        $resolver->setDefaultConnection('reporting');
        $this->assertSame('reporting', $resolver->getDefaultConnection());

        $resolver->setDefaultConnection(null);
        $this->assertSame('pgsql', $resolver->getDefaultConnection());
    }

    public function testOverrideInOneCoroutineIsNotVisibleInSibling(): void
    {
        $resolver = $this->makeResolver('pgsql');

        $observations = [];

        run(function () use ($resolver, &$observations): void {
            Coroutine::create(function () use ($resolver, &$observations) {
                $resolver->setDefaultConnection('reporting');
                $observations['parent'] = $resolver->getDefaultConnection();

                Coroutine::create(function () use ($resolver, &$observations) {
                    $observations['sibling'] = $resolver->getDefaultConnection();
                });
            });
        });

        $this->assertSame('reporting', $observations['parent']);
        $this->assertSame(
            'pgsql',
            $observations['sibling'],
            'Sibling coroutine must see config-derived default, not the parent\'s override',
        );
    }

    public function testNestedOverrideRestoresExactPriorValue(): void
    {
        $resolver = $this->makeResolver('pgsql');

        $resolver->setDefaultConnection('outer');
        $this->assertSame('outer', $resolver->getDefaultConnection());

        $previous = CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
        try {
            $resolver->setDefaultConnection('inner');
            $this->assertSame('inner', $resolver->getDefaultConnection());
        } finally {
            if ($previous === null) {
                CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
            } else {
                CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, $previous);
            }
        }

        $this->assertSame('outer', $resolver->getDefaultConnection());
    }

    public function testNonCoroutineConnectionIsRetainedUntilTerminalRelease(): void
    {
        $factory = m::mock(PoolFactory::class);
        $pool = m::mock(DbPool::class);
        $firstWrapper = m::mock(PooledConnection::class);
        $secondWrapper = m::mock(PooledConnection::class);
        $firstConnection = m::mock(Connection::class);
        $secondConnection = m::mock(Connection::class);

        $factory->expects('getPool')->twice()->with('mysql')->andReturn($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->andReturnNull();
        $pool->expects('get')->twice()->andReturn($firstWrapper, $secondWrapper);
        $firstWrapper->expects('getConnection')->andReturn($firstConnection);
        $firstWrapper->expects('release');
        $secondWrapper->expects('getConnection')->andReturn($secondConnection);
        $secondWrapper->expects('release');

        $resolver = $this->makeResolver('mysql', $factory);

        $this->assertSame($firstConnection, $resolver->connection());
        $this->assertSame($firstConnection, $resolver->connection());

        $resolver->releaseConnections();

        $this->assertSame($secondConnection, $resolver->connection());

        $resolver->releaseConnections();
    }

    public function testTerminalReleaseOwnsEveryRequestedConnectionRole(): void
    {
        $factory = m::mock(PoolFactory::class);
        $resolver = $this->makeResolver('mysql', $factory);

        foreach (['mysql', 'mysql::read', 'mysql::write'] as $name) {
            $pool = m::mock(DbPool::class);
            $wrapper = m::mock(PooledConnection::class);
            $connection = m::mock(Connection::class);

            $factory->expects('getPool')->once()->with($name)->andReturn($pool);
            $pool->allows('getSharedInMemorySqlitePdo')->andReturnNull();
            $pool->expects('get')->once()->andReturn($wrapper);
            $wrapper->expects('getConnection')->andReturn($connection);
            $wrapper->expects('release');

            if ($name === 'mysql::write') {
                $connection->expects('useWriteConnectionWhenReading');
            }

            $this->assertSame($connection, $resolver->connection($name));
        }

        $resolver->releaseConnections();
    }

    public function testSharedInMemorySqliteAliasesReuseOneConnectionOwner(): void
    {
        $factory = m::mock(PoolFactory::class);
        $pool = m::mock(DbPool::class);
        $wrapper = m::mock(PooledConnection::class);
        $connection = m::mock(Connection::class);

        $factory->expects('getPool')->once()->with('sqlite')->andReturn($pool);
        $factory->expects('getPool')->once()->with('sqlite::read')->andReturn($pool);
        $factory->expects('getPool')->once()->with('sqlite::write')->andReturn($pool);
        $pool->expects('getSharedInMemorySqlitePdo')->times(3)->andReturn(m::mock(PDO::class));
        $pool->expects('getName')->times(3)->andReturn('sqlite');
        $pool->expects('get')->once()->andReturn($wrapper);
        $wrapper->expects('getConnection')->once()->andReturn($connection);
        $connection->expects('useWriteConnectionWhenReading')->once();
        $wrapper->expects('release')->once();

        $resolver = $this->makeResolver('sqlite', $factory);

        $this->assertSame($connection, $resolver->connection('sqlite'));
        $this->assertSame($connection, $resolver->connection('sqlite::read'));
        $this->assertSame($connection, $resolver->connection('sqlite::write'));

        $resolver->releaseConnections();
    }

    public function testTerminalStateIsDetachedBeforeReleaseCanReenterTheResolver(): void
    {
        $factory = m::mock(PoolFactory::class);
        $pool = m::mock(DbPool::class);
        $firstWrapper = m::mock(PooledConnection::class);
        $secondWrapper = m::mock(PooledConnection::class);
        $firstConnection = m::mock(Connection::class);
        $secondConnection = m::mock(Connection::class);

        $factory->expects('getPool')->twice()->with('mysql')->andReturn($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->andReturnNull();
        $pool->expects('get')->twice()->andReturn($firstWrapper, $secondWrapper);
        $firstWrapper->expects('getConnection')->andReturn($firstConnection);
        $secondWrapper->expects('getConnection')->andReturn($secondConnection);
        $secondWrapper->expects('release');

        $resolver = $this->makeResolver('mysql', $factory);
        $resolver->setDefaultConnection('reporting');

        $firstWrapper->expects('release')->andReturnUsing(function () use ($resolver, $secondConnection): void {
            $this->assertSame('mysql', $resolver->getDefaultConnection());
            $this->assertSame($secondConnection, $resolver->connection('mysql'));
        });

        $this->assertSame($firstConnection, $resolver->connection('mysql'));

        $resolver->releaseConnections();
        $resolver->releaseConnections();
    }

    public function testTerminalReleaseExhaustsConnectionsAndPreservesTheFirstFailure(): void
    {
        $firstException = new RuntimeException('First release failed.');
        $secondException = new RuntimeException('Second release failed.');
        $factory = m::mock(PoolFactory::class);
        $resolver = $this->makeResolver('first', $factory);

        foreach ([
            ['first', $firstException],
            ['second', $secondException],
        ] as [$name, $exception]) {
            $pool = m::mock(DbPool::class);
            $wrapper = m::mock(PooledConnection::class);

            $factory->expects('getPool')->once()->with($name)->andReturn($pool);
            $pool->allows('getSharedInMemorySqlitePdo')->andReturnNull();
            $pool->expects('get')->once()->andReturn($wrapper);
            $wrapper->expects('getConnection')->andReturn(m::mock(Connection::class));
            $wrapper->expects('release')->andThrow($exception);

            $resolver->connection($name);
        }

        try {
            $resolver->releaseConnections();
            $this->fail('Expected the first release failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($firstException, $throwable);
        }

        $resolver->releaseConnections();
    }

    public function testTerminalDiscardExhaustsExactConnections(): void
    {
        $factory = m::mock(PoolFactory::class);
        $resolver = $this->makeResolver('first', $factory);

        foreach (['first', 'second'] as $name) {
            $pool = m::mock(DbPool::class);
            $wrapper = m::mock(PooledConnection::class);

            $factory->expects('getPool')->once()->with($name)->andReturn($pool);
            $pool->allows('getSharedInMemorySqlitePdo')->andReturnNull();
            $pool->expects('get')->once()->andReturn($wrapper);
            $wrapper->expects('getConnection')->andReturn(m::mock(Connection::class));
            $wrapper->expects('discard');

            $resolver->connection($name);
        }

        $resolver->discardConnections();
        $resolver->discardConnections();
    }

    public function testConnectionRetrievalFailureDiscardsTheExactWrapper(): void
    {
        $exception = new RuntimeException('Connection retrieval failed.');
        $factory = m::mock(PoolFactory::class);
        $pool = m::mock(DbPool::class);
        $wrapper = m::mock(PooledConnection::class);

        $factory->expects('getPool')->once()->with('mysql')->andReturn($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->andReturnNull();
        $pool->expects('get')->once()->andReturn($wrapper);
        $wrapper->expects('getConnection')->andThrow($exception);
        $wrapper->expects('discard');

        $resolver = $this->makeResolver('mysql', $factory);

        try {
            $resolver->connection();
            $this->fail('Expected the connection retrieval failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testWriteRoleConfigurationFailureDiscardsTheExactWrapper(): void
    {
        $exception = new RuntimeException('Write role configuration failed.');
        $factory = m::mock(PoolFactory::class);
        $pool = m::mock(DbPool::class);
        $wrapper = m::mock(PooledConnection::class);
        $connection = m::mock(Connection::class);

        $factory->expects('getPool')->once()->with('mysql::write')->andReturn($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->andReturnNull();
        $pool->expects('get')->once()->andReturn($wrapper);
        $wrapper->expects('getConnection')->andReturn($connection);
        $connection->expects('useWriteConnectionWhenReading')->andThrow($exception);
        $wrapper->expects('discard');

        $resolver = $this->makeResolver('mysql', $factory);

        try {
            $resolver->connection('mysql::write');
            $this->fail('Expected the write role configuration failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testDiscardFailureDoesNotReplaceTheSetupFailure(): void
    {
        $setupException = new RuntimeException('Connection retrieval failed.');
        $discardException = new RuntimeException('Discard failed.');
        $factory = m::mock(PoolFactory::class);
        $pool = m::mock(DbPool::class);
        $wrapper = m::mock(PooledConnection::class);

        $factory->expects('getPool')->once()->with('mysql')->andReturn($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->andReturnNull();
        $pool->expects('get')->once()->andReturn($wrapper);
        $wrapper->expects('getConnection')->andThrow($setupException);
        $wrapper->expects('discard')->andThrow($discardException);

        $resolver = $this->makeResolver('mysql', $factory);

        try {
            $resolver->connection();
            $this->fail('Expected the connection retrieval failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($setupException, $throwable);
        }
    }

    public function testCoroutineConnectionRemainsDeferOwned(): void
    {
        $factory = m::mock(PoolFactory::class);
        $pool = m::mock(DbPool::class);
        $wrapper = m::mock(PooledConnection::class);
        $connection = m::mock(Connection::class);

        $factory->expects('getPool')->once()->with('mysql')->andReturn($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->andReturnNull();
        $pool->expects('get')->once()->andReturn($wrapper);
        $wrapper->expects('getConnection')->andReturn($connection);
        $wrapper->expects('release');

        $resolver = $this->makeResolver('mysql', $factory);

        run(function () use ($resolver, $connection): void {
            $this->assertSame($connection, $resolver->connection());
            $resolver->releaseConnections();
        });

        $resolver->releaseConnections();
    }

    protected function makeResolver(
        string $configuredDefault,
        ?PoolFactory $factory = null,
    ): ConnectionResolver {
        $app = Container::getInstance();
        $app->instance('config', new Repository([
            'database' => ['default' => $configuredDefault],
        ]));
        $app->instance(PoolFactory::class, $factory ?? m::mock(PoolFactory::class));

        return new ConnectionResolver($app);
    }
}
