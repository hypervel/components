<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Pool\DatabasePool;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Database\Query\Grammars\SQLiteGrammar;
use Hypervel\Engine\Coroutine;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\run;

/**
 * Regression tests for ConnectionResolver::setDefaultConnection() using
 * CoroutineContext. Mirrors the DatabaseManager tests since both
 * implementations share the same Context key and semantics.
 */
class ConnectionResolverTest extends TestCase
{
    use VerifiesDoubles;

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
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $firstWrapper = Double::for(PooledConnection::class)->strict();
        $firstWrapper->allows('recordDateFormat');
        $firstWrapper->allows('usesSessionLeases')->returns(false);
        $secondWrapper = Double::for(PooledConnection::class)->strict();
        $secondWrapper->allows('recordDateFormat');
        $secondWrapper->allows('usesSessionLeases')->returns(false);
        $firstConnection = Double::for(Connection::class)->strict();
        $secondConnection = Double::for(Connection::class)->strict();

        $poolManager->expects('pool')->times(2)->with('mysql')->returns($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->expects('borrow')->times(2)->returns($firstWrapper, $secondWrapper);
        $firstWrapper->expects('getConnection')->returns($firstConnection);
        $firstWrapper->expects('dispatchConnectionEstablishedEvent');
        $firstWrapper->expects('release');
        $secondWrapper->expects('getConnection')->returns($secondConnection);
        $secondWrapper->expects('dispatchConnectionEstablishedEvent');
        $secondWrapper->expects('release');

        $resolver = $this->makeResolver('mysql', $poolManager);

        $this->assertSame($firstConnection, $resolver->connection());
        $this->assertSame($firstConnection, $resolver->connection());

        $resolver->releaseConnections();

        $this->assertSame($secondConnection, $resolver->connection());

        $resolver->releaseConnections();
    }

    public function testTerminalReleaseOwnsEveryRequestedConnectionRole(): void
    {
        $poolManager = Double::for(PoolManager::class)->strict();
        $resolver = $this->makeResolver('mysql', $poolManager);

        foreach (['mysql' => null, 'mysql::read' => 'read', 'mysql::write' => 'write'] as $name => $role) {
            $pool = Double::for(DatabasePool::class)->strict();
            $wrapper = Double::for(PooledConnection::class)->strict();
            $wrapper->allows('recordDateFormat');
            $wrapper->allows('usesSessionLeases')->returns(false);
            $connection = Double::for(Connection::class)->strict();

            $poolManager->expects('pool')->with($name)->returns($pool);
            $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
            $pool->expects('borrow')->returns($wrapper);
            $wrapper->expects('getConnection')->returns($connection);
            $wrapper->expects('dispatchConnectionEstablishedEvent');
            $wrapper->expects('release');

            if ($role !== null) {
                $connection->expects('setReadWriteType')->with($role);
            }

            if ($name === 'mysql::write') {
                $connection->expects('useWriteConnectionWhenReading');
            }

            $this->assertSame($connection, $resolver->connection($name));
        }

        $resolver->releaseConnections();
    }

    public function testBorrowedConnectionRetainsItsRequestedRole(): void
    {
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $resolver = $this->makeResolver('sqlite', $poolManager);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);

        $wrappers = [
            'sqlite::write' => Double::for(PooledConnection::class)->strict(),
            'sqlite' => Double::for(PooledConnection::class)->strict(),
        ];
        $pool->expects('borrow')->times(2)->returns(...array_values($wrappers));

        foreach ($wrappers as $name => $wrapper) {
            $connection = new PdoConnection(new PDO('sqlite::memory:'), config: ['name' => 'sqlite']);
            $wrapper->allows('recordDateFormat');
            $wrapper->allows('usesSessionLeases')->returns(false);
            $poolManager->expects('pool')->with($name)->returns($pool);
            $wrapper->expects('getConnection')->returns($connection);
            $wrapper->expects('dispatchConnectionEstablishedEvent');
            $wrapper->expects('release');

            $this->assertSame($connection, $resolver->connection($name));
            $this->assertSame($name, $connection->getNameWithReadWriteType());
        }

        $resolver->releaseConnections();
    }

    public function testSharedInMemorySqliteAliasesReuseOneConnectionOwner(): void
    {
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $wrapper = Double::for(PooledConnection::class)->strict();
        $wrapper->allows('recordDateFormat');
        $wrapper->allows('usesSessionLeases')->returns(false);
        $connection = Double::for(Connection::class)->strict();

        $poolManager->expects('pool')->with('sqlite::read')->returns($pool);
        $poolManager->expects('pool')->with('sqlite::write')->returns($pool);
        $pool->expects('getSharedInMemorySqlitePdo')->times(2)->returns(Double::for(PDO::class)->strict());
        $pool->expects('getName')->times(2)->returns('sqlite');
        $pool->expects('borrow')->returns($wrapper);
        $wrapper->expects('getConnection')->returns($connection);
        $wrapper->expects('dispatchConnectionEstablishedEvent');
        $connection->expects('useWriteConnectionWhenReading');
        $wrapper->expects('release');

        $resolver = $this->makeResolver('sqlite', $poolManager);

        $this->assertSame($connection, $resolver->connection('sqlite::write'));
        $this->assertSame($connection, $resolver->connection('sqlite::read'));
        $this->assertSame($connection, $resolver->connection('sqlite'));

        $resolver->releaseConnections();
    }

    public function testTerminalStateRemainsAvailableUntilItsOwnerIsReleased(): void
    {
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $firstWrapper = Double::for(PooledConnection::class)->strict();
        $firstWrapper->allows('recordDateFormat');
        $firstWrapper->allows('usesSessionLeases')->returns(false);
        $secondWrapper = Double::for(PooledConnection::class)->strict();
        $secondWrapper->allows('recordDateFormat');
        $secondWrapper->allows('usesSessionLeases')->returns(false);
        $firstConnection = Double::for(Connection::class)->strict();
        $secondConnection = Double::for(Connection::class)->strict();

        $poolManager->expects('pool')->times(2)->with('mysql')->returns($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->expects('borrow')->times(2)->returns($firstWrapper, $secondWrapper);
        $firstWrapper->expects('getConnection')->returns($firstConnection);
        $firstWrapper->expects('dispatchConnectionEstablishedEvent');
        $secondWrapper->expects('getConnection')->returns($secondConnection);
        $secondWrapper->expects('dispatchConnectionEstablishedEvent');
        $secondWrapper->expects('release');

        $resolver = $this->makeResolver('mysql', $poolManager);
        $resolver->setDefaultConnection('reporting');

        $firstWrapper->expects('release')->resolves(function () use ($resolver, $firstConnection): void {
            $this->assertSame('reporting', $resolver->getDefaultConnection());
            $this->assertSame($firstConnection, $resolver->connection('mysql'));
        });

        $this->assertSame($firstConnection, $resolver->connection('mysql'));

        $resolver->releaseConnections();
        $this->assertSame('mysql', $resolver->getDefaultConnection());
        $this->assertSame($secondConnection, $resolver->connection('mysql'));
        $resolver->releaseConnections();
    }

    public function testTerminalReleaseExhaustsConnectionsAndPreservesTheFirstFailure(): void
    {
        $firstException = new RuntimeException('First release failed.');
        $secondException = new RuntimeException('Second release failed.');
        $poolManager = Double::for(PoolManager::class)->strict();
        $resolver = $this->makeResolver('first', $poolManager);

        foreach ([
            ['first', $firstException],
            ['second', $secondException],
        ] as [$name, $exception]) {
            $pool = Double::for(DatabasePool::class)->strict();
            $wrapper = Double::for(PooledConnection::class)->strict();
            $wrapper->allows('recordDateFormat');
            $wrapper->allows('usesSessionLeases')->returns(false);

            $poolManager->expects('pool')->with($name)->returns($pool);
            $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
            $pool->expects('borrow')->returns($wrapper);
            $wrapper->expects('getConnection')->returns(Double::for(Connection::class)->strict());
            $wrapper->expects('dispatchConnectionEstablishedEvent');
            $wrapper->expects('release')->throws($exception);

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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testTerminalReleasePrioritizesTheFirstCancellationAndStillExhaustsConnections(bool $chained): void
    {
        $ordinaryFailure = new RuntimeException('First release failed.');
        $firstCancellation = new CanceledException('Second release was canceled.');
        if ($chained) {
            $firstCancellation = new RuntimeException('Release cleanup failed.', previous: $firstCancellation);
        }
        $secondCancellation = new CanceledException('Third release was canceled.');
        $poolManager = Double::for(PoolManager::class)->strict();
        $resolver = $this->makeResolver('first', $poolManager);

        foreach ([
            ['first', $ordinaryFailure],
            ['second', $firstCancellation],
            ['third', $secondCancellation],
        ] as [$name, $failure]) {
            $pool = Double::for(DatabasePool::class)->strict();
            $wrapper = Double::for(PooledConnection::class)->strict();
            $wrapper->allows('recordDateFormat');
            $wrapper->allows('usesSessionLeases')->returns(false);

            $poolManager->expects('pool')->with($name)->returns($pool);
            $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
            $pool->expects('borrow')->returns($wrapper);
            $wrapper->expects('getConnection')->returns(Double::for(Connection::class)->strict());
            $wrapper->expects('dispatchConnectionEstablishedEvent');
            $wrapper->expects('release')->throws($failure);

            $resolver->connection($name);
        }

        try {
            $resolver->releaseConnections();
            $this->fail('Expected release cancellation to propagate.');
        } catch (Throwable $throwable) {
            $this->assertSame($firstCancellation, $throwable);
        }

        $resolver->releaseConnections();
    }

    public function testTerminalDiscardExhaustsExactConnections(): void
    {
        $poolManager = Double::for(PoolManager::class)->strict();
        $resolver = $this->makeResolver('first', $poolManager);

        foreach (['first', 'second'] as $name) {
            $pool = Double::for(DatabasePool::class)->strict();
            $wrapper = Double::for(PooledConnection::class)->strict();
            $wrapper->allows('recordDateFormat');
            $wrapper->allows('usesSessionLeases')->returns(false);

            $poolManager->expects('pool')->with($name)->returns($pool);
            $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
            $pool->expects('borrow')->returns($wrapper);
            $wrapper->expects('getConnection')->returns(Double::for(Connection::class)->strict());
            $wrapper->expects('dispatchConnectionEstablishedEvent');
            $wrapper->expects('discard');

            $resolver->connection($name);
        }

        $resolver->discardConnections();
        $resolver->discardConnections();
    }

    public function testConnectionRetrievalFailureDiscardsTheExactWrapper(): void
    {
        $exception = new RuntimeException('Connection retrieval failed.');
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $wrapper = Double::for(PooledConnection::class)->strict();
        $wrapper->allows('usesSessionLeases')->returns(false);

        $poolManager->expects('pool')->with('mysql')->returns($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->expects('borrow')->returns($wrapper);
        $wrapper->expects('getConnection')->throws($exception);
        $wrapper->expects('discard');

        $resolver = $this->makeResolver('mysql', $poolManager);

        try {
            $resolver->connection();
            $this->fail('Expected the connection retrieval failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testListenerFailureDiscardsTheExactWrapperWithoutDeferringRelease(): void
    {
        $exception = new RuntimeException('Connection listener failed.');
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $wrapper = Double::for(PooledConnection::class)->strict();
        $wrapper->allows('usesSessionLeases')->returns(false);

        $poolManager->expects('pool')->with('mysql')->returns($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->expects('borrow')->returns($wrapper);
        $wrapper->expects('getConnection')->returns(Double::for(Connection::class)->strict());
        $wrapper->expects('dispatchConnectionEstablishedEvent')->throws($exception);
        $wrapper->expects('discard');
        $wrapper->expects('release')->never();

        $resolver = $this->makeResolver('mysql', $poolManager);
        $caught = null;

        run(function () use ($resolver, &$caught): void {
            try {
                $resolver->connection();
            } catch (RuntimeException $throwable) {
                $caught = $throwable;
            }
        });

        $this->assertSame($exception, $caught);
    }

    public function testWriteRoleConfigurationFailureDiscardsTheExactWrapper(): void
    {
        $exception = new RuntimeException('Write role configuration failed.');
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $wrapper = Double::for(PooledConnection::class)->strict();
        $wrapper->allows('usesSessionLeases')->returns(false);
        $connection = Double::for(Connection::class)->strict();

        $poolManager->expects('pool')->with('mysql::write')->returns($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->expects('borrow')->returns($wrapper);
        $wrapper->expects('getConnection')->returns($connection);
        $connection->expects('setReadWriteType')->with('write');
        $connection->expects('useWriteConnectionWhenReading')->throws($exception);
        $wrapper->expects('discard');

        $resolver = $this->makeResolver('mysql', $poolManager);

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
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $wrapper = Double::for(PooledConnection::class)->strict();
        $wrapper->allows('usesSessionLeases')->returns(false);

        $poolManager->expects('pool')->with('mysql')->returns($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->expects('borrow')->returns($wrapper);
        $wrapper->expects('getConnection')->throws($setupException);
        $wrapper->expects('discard')->throws($discardException);

        $resolver = $this->makeResolver('mysql', $poolManager);

        try {
            $resolver->connection();
            $this->fail('Expected the connection retrieval failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($setupException, $throwable);
        }
    }

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'cleanup failure carrying cancellation')]
    public function testDiscardCancellationReplacesAnOrdinarySetupFailure(bool $chained): void
    {
        $setupException = new RuntimeException('Connection retrieval failed.');
        $discardCancellation = new CanceledException('Discard was canceled.');
        $discardCancellation = $chained ? new RuntimeException('Discard cleanup failed.', previous: $discardCancellation) : $discardCancellation;
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $wrapper = Double::for(PooledConnection::class)->strict();
        $wrapper->allows('usesSessionLeases')->returns(false);

        $poolManager->expects('pool')->with('mysql')->returns($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->expects('borrow')->returns($wrapper);
        $wrapper->expects('getConnection')->throws($setupException);
        $wrapper->expects('discard')->throws($discardCancellation);

        $resolver = $this->makeResolver('mysql', $poolManager);

        try {
            $resolver->connection();
            $this->fail('Expected discard cancellation to propagate.');
        } catch (Throwable $throwable) {
            $this->assertSame($discardCancellation, $throwable);
        }
    }

    public function testSetupCancellationRemainsPrimaryOverAnOrdinaryDiscardFailure(): void
    {
        $setupCancellation = new CanceledException('Connection setup was canceled.');
        $discardException = new RuntimeException('Discard failed.');
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $wrapper = Double::for(PooledConnection::class)->strict();
        $wrapper->allows('usesSessionLeases')->returns(false);

        $poolManager->expects('pool')->with('mysql')->returns($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->expects('borrow')->returns($wrapper);
        $wrapper->expects('getConnection')->throws($setupCancellation);
        $wrapper->expects('discard')->throws($discardException);

        $resolver = $this->makeResolver('mysql', $poolManager);

        try {
            $resolver->connection();
            $this->fail('Expected setup cancellation to propagate.');
        } catch (CanceledException $throwable) {
            $this->assertSame($setupCancellation, $throwable);
        }
    }

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'setup failure carrying cancellation')]
    public function testSetupCancellationRemainsPrimaryOverDiscardCancellation(bool $chained): void
    {
        $setupCancellation = new CanceledException('Connection setup was canceled.');
        $setupCancellation = $chained ? new RuntimeException('Connection setup cleanup failed.', previous: $setupCancellation) : $setupCancellation;
        $discardCancellation = new CanceledException('Discard was canceled.');
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $wrapper = Double::for(PooledConnection::class)->strict();
        $wrapper->allows('usesSessionLeases')->returns(false);

        $poolManager->expects('pool')->with('mysql')->returns($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->expects('borrow')->returns($wrapper);
        $wrapper->expects('getConnection')->throws($setupCancellation);
        $wrapper->expects('discard')->throws($discardCancellation);

        $resolver = $this->makeResolver('mysql', $poolManager);

        try {
            $resolver->connection();
            $this->fail('Expected setup cancellation to propagate.');
        } catch (Throwable $throwable) {
            $this->assertSame($setupCancellation, $throwable);
        }
    }

    public function testCoroutineConnectionRemainsDeferOwned(): void
    {
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $wrapper = Double::for(PooledConnection::class)->strict();
        $wrapper->allows('recordDateFormat');
        $wrapper->allows('usesSessionLeases')->returns(false);
        $connection = Double::for(Connection::class)->strict();

        $poolManager->expects('pool')->with('mysql')->returns($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->expects('borrow')->returns($wrapper);
        $wrapper->expects('getConnection')->returns($connection);
        $wrapper->expects('dispatchConnectionEstablishedEvent');
        $wrapper->expects('release');

        $resolver = $this->makeResolver('mysql', $poolManager);

        run(function () use ($resolver, $connection): void {
            $this->assertSame($connection, $resolver->connection());
            $resolver->releaseConnections();
        });

        $resolver->releaseConnections();
    }

    public function testConnectionDateFormatReadsThePoolsRecordWithoutBorrowing(): void
    {
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $poolManager->expects('existing')->with('pgsql')->returns($pool);
        $poolManager->expects('existing')->with('pgsql::write')->returns($pool);
        $poolManager->expects('pool')->never();
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->allows('recordedDateFormat')->returns('Y-m-d H:i:s.u');
        $pool->expects('borrow')->never();
        $resolver = $this->makeResolver('pgsql', $poolManager);

        run(function () use ($resolver): void {
            $this->assertSame('Y-m-d H:i:s.u', $resolver->connectionDateFormat());
            $this->assertSame('Y-m-d H:i:s.u', $resolver->connectionDateFormat('pgsql::write'));
            $this->assertFalse(CoroutineContext::has('__database.connection.pgsql'));
        });
    }

    public function testConnectionDateFormatPrefersTheGrammarOfAHeldConnection(): void
    {
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $wrapper = Double::for(PooledConnection::class)->strict();
        $wrapper->allows('usesSessionLeases')->returns(false);
        $connection = new PdoConnection(new PDO('sqlite::memory:'), config: ['name' => 'sqlite']);
        $poolManager->expects('pool')->with('sqlite')->returns($pool);
        $poolManager->expects('existing')->never();
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->expects('borrow')->returns($wrapper);
        $wrapper->expects('recordDateFormat');
        $wrapper->expects('getConnection')->returns($connection);
        $wrapper->expects('dispatchConnectionEstablishedEvent');
        $wrapper->expects('release');
        $resolver = $this->makeResolver('sqlite', $poolManager);

        run(function () use ($resolver, $connection): void {
            $resolver->connection();
            $connection->setQueryGrammar(new ConnectionResolverTestTimestampGrammar($connection));

            $this->assertSame('U', $resolver->connectionDateFormat('sqlite'));
            $this->assertSame($connection, $resolver->getHeldConnection());
            $this->assertSame($connection, $resolver->getHeldConnection(''));
            $this->assertSame($connection, $resolver->getHeldConnection(ConnectionResolverTestName::Sqlite));
        });
    }

    public function testSharedInMemorySqliteAliasesReadTheFormatOfTheirHeldOwner(): void
    {
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $wrapper = Double::for(PooledConnection::class)->strict();
        $wrapper->allows('usesSessionLeases')->returns(false);
        $connection = new PdoConnection(new PDO('sqlite::memory:'), config: ['name' => 'sqlite']);
        $connection->setQueryGrammar(new ConnectionResolverTestTimestampGrammar($connection));
        $poolManager->expects('pool')->with('sqlite::write')->returns($pool);
        $poolManager->expects('existing')->with('sqlite::read')->returns($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(Double::for(PDO::class)->strict());
        $pool->allows('getName')->returns('sqlite');
        $wrapper->expects('recordDateFormat');
        $pool->expects('recordedDateFormat')->never();
        $pool->expects('borrow')->returns($wrapper);
        $wrapper->expects('getConnection')->returns($connection);
        $wrapper->expects('dispatchConnectionEstablishedEvent');
        $wrapper->expects('release');
        $resolver = $this->makeResolver('sqlite', $poolManager);

        run(function () use ($resolver): void {
            $resolver->connection('sqlite::write');

            $this->assertSame('U', $resolver->connectionDateFormat('sqlite::read'));
        });
    }

    public function testConnectionDateFormatResolvesTheConnectionWhileNoOpenPoolHasRecordedOne(): void
    {
        $poolManager = Double::for(PoolManager::class)->strict();
        $pool = Double::for(DatabasePool::class)->strict();
        $wrapper = Double::for(PooledConnection::class)->strict();
        $wrapper->allows('usesSessionLeases')->returns(false);
        $connection = new PdoConnection(new PDO('sqlite::memory:'), config: ['name' => 'sqlite']);
        // A purged pool is no longer found, so its old record cannot answer.
        $poolManager->expects('existing')->times(2)->with('sqlite')->returns(null, $pool);
        $poolManager->expects('pool')->with('sqlite')->returns($pool);
        $pool->allows('getSharedInMemorySqlitePdo')->returns(null);
        $pool->expects('borrow')->returns($wrapper);
        $wrapper->expects('recordDateFormat');
        $wrapper->expects('getConnection')->returns($connection);
        $wrapper->expects('dispatchConnectionEstablishedEvent');
        $wrapper->expects('release');
        $resolver = $this->makeResolver('sqlite', $poolManager);

        run(function () use ($resolver): void {
            $this->assertSame('Y-m-d H:i:s', $resolver->connectionDateFormat('sqlite'));
            $this->assertTrue(CoroutineContext::has('__database.connection.sqlite'));
        });
    }

    protected function makeResolver(
        string $configuredDefault,
        ?PoolManager $poolManager = null,
    ): ConnectionResolver {
        $app = Container::getInstance();
        $app->instance('config', new Repository([
            'database' => ['default' => $configuredDefault],
        ]));
        $app->instance(PoolManager::class, $poolManager ?? Double::for(PoolManager::class)->strict());

        return new ConnectionResolver($app);
    }
}

enum ConnectionResolverTestName: string
{
    case Sqlite = 'sqlite';
}

class ConnectionResolverTestTimestampGrammar extends SQLiteGrammar
{
    /**
     * Store dates as Unix timestamps.
     */
    public function getDateFormat(): string
    {
        return 'U';
    }
}
