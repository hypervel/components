<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseConnectionTest;

use DateTime;
use ErrorException;
use Exception;
use Generator;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\DeadlockException;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\QueryFailed;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionCommitting;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Database\LostConnectionException;
use Hypervel\Database\MariaDbConnection;
use Hypervel\Database\MultipleColumnsSelectedException;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Query\Builder as BaseBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Builder;
use Hypervel\Database\Schema\Grammars\Grammar as SchemaGrammar;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Testbench\TestCase;
use LogicException;
use Mockery as m;
use PDO;
use PDOException;
use ReflectionClass;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class DatabaseConnectionTest extends TestCase
{
    public function testDriverNameFallsBackToTheConnectionIdentity(): void
    {
        $pdo = new PDOStub;

        foreach ([
            'pdo' => new PdoConnection($pdo),
            'mysql' => new MySqlConnection($pdo),
            'mariadb' => new MariaDbConnection($pdo),
            'pgsql' => new PostgresConnection($pdo),
            'sqlite' => new SQLiteConnection($pdo),
            'http' => new NeutralConnectionForTest,
        ] as $driver => $connection) {
            $this->assertSame($driver, $connection->getDriverName());
        }
    }

    public function testConfiguredDriverNameOverridesTheConnectionIdentity(): void
    {
        $connection = new SQLiteConnection(
            new PDO('sqlite::memory:'),
            config: ['driver' => 'custom-sqlite'],
        );

        $this->assertSame('custom-sqlite', $connection->getDriverName());
    }

    public function testDefaultPdoDriverNameDoesNotResolveTheLazyConnection(): void
    {
        $connection = new PdoConnection(
            static fn (): never => throw new RuntimeException('The lazy PDO should not be resolved.'),
        );

        $this->assertSame('pdo', $connection->getDriverName());
    }

    public function testConfiglessQueryFailureRetainsTheOriginalDatabaseException(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $exception = null;

        try {
            $connection->statement('invalid sql');
        } catch (QueryException $thrown) {
            $exception = $thrown;
        }

        $this->assertInstanceOf(QueryException::class, $exception);
        $this->assertNull($exception->getConnectionName());
        $this->assertInstanceOf(PDOException::class, $exception->getPrevious());
    }

    public function testConfiglessDatabaseEventsRetainTheNullableConnectionName(): void
    {
        $queryConnection = new PdoConnection(new PDO('sqlite::memory:'));
        $queryConnection->setEventDispatcher($queryEvents = m::mock(Dispatcher::class));
        $queryEvents->shouldReceive('hasListeners')->once()->with(QueryExecuted::class)->andReturn(true);
        $queryEvents->shouldReceive('dispatch')->once()->with(m::on(
            static fn (object $event): bool => $event instanceof QueryExecuted
                && $event->connectionName === null
        ));

        $queryConnection->logQuery('select 1', [], 0.0);

        $transactionConnection = new PdoConnection(new PDO('sqlite::memory:'));
        $transactionConnection->setEventDispatcher($transactionEvents = m::mock(Dispatcher::class));
        $transactionEvents->shouldReceive('hasListeners')->once()->with(TransactionBeginning::class)->andReturn(true);
        $transactionEvents->shouldReceive('dispatch')->once()->with(m::on(
            static fn (object $event): bool => $event instanceof TransactionBeginning
                && $event->connectionName === null
        ));

        $transactionConnection->beginTransaction();
        $transactionConnection->unsetEventDispatcher();
        $transactionConnection->rollBack();
    }

    public function testConfiglessTransactionManagerUsesTheDefaultConnectionKey(): void
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);

        $connection->beginTransaction();

        $this->assertSame('', $manager->getPendingTransactions()->first()->connection);

        $connection->disconnect();

        $this->assertCount(0, $manager->getPendingTransactions());
        $this->assertCount(0, $manager->getCommittedTransactions());
        $this->assertNull($connection->getRawPdo());
    }

    public function testConfiglessCommitCallbacksRemainScopedToTheirConnection(): void
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);

        $connection->beginTransaction();
        $manager->begin('named', 1);

        $committed = false;
        $connection->afterCommit(function () use (&$committed): void {
            $committed = true;
        });

        $connection->commit();

        $this->assertTrue($committed);

        $executedImmediately = false;
        $connection->afterCommit(function () use (&$executedImmediately): void {
            $executedImmediately = true;
        });

        $this->assertTrue($executedImmediately);

        $manager->rollback('named', 0);
    }

    public function testConfiglessRollbackCallbacksRemainScopedToTheirConnection(): void
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);

        $connection->beginTransaction();
        $manager->begin('named', 1);

        $rolledBack = false;
        $connection->afterRollBack(function () use (&$rolledBack): void {
            $rolledBack = true;
        });

        $connection->rollBack();

        $this->assertTrue($rolledBack);

        $calledWithoutOwnTransaction = false;
        $connection->afterRollBack(function () use (&$calledWithoutOwnTransaction): void {
            $calledWithoutOwnTransaction = true;
        });

        $manager->rollback('named', 0);

        $this->assertFalse($calledWithoutOwnTransaction);
    }

    public function testFlushStateClearsResolversAndMacros()
    {
        try {
            Connection::resolverFor('custom', fn () => null);
            Connection::macro('stateTest', fn () => 'state');

            $this->assertNotNull(Connection::getResolver('custom'));
            $this->assertTrue(Connection::hasMacro('stateTest'));

            Connection::flushState();

            $this->assertNull(Connection::getResolver('custom'));
            $this->assertFalse(Connection::hasMacro('stateTest'));
        } finally {
            Connection::flushState();
        }
    }

    public function testNeutralConnectionDoesNotExposePdoResourceMethods(): void
    {
        $connection = new ReflectionClass(Connection::class);

        foreach (['getPdo', 'getRawPdo', 'getReadPdo', 'getRawReadPdo', 'setPdo', 'setReadPdo'] as $method) {
            $this->assertFalse($connection->hasMethod($method));
        }
    }

    public function testNeutralConnectionProvidesPreciseUnsupportedCapabilityErrors(): void
    {
        $connection = new NeutralConnectionForTest(
            'analytics',
            '',
            ['name' => 'analytics', 'driver' => 'http']
        );

        foreach ([
            [
                static fn () => $connection->selectResultSets('select 1'),
                LogicException::class,
                'Database driver [http] does not support multiple result sets.',
            ],
            [
                static fn () => $connection->getLastInsertId(),
                LogicException::class,
                'Database driver [http] does not support retrieving last insert IDs.',
            ],
            [
                static fn () => $connection->getSchemaState(),
                RuntimeException::class,
                'This database driver does not support schema state.',
            ],
            [
                static fn () => $connection->executeSessionStatement('set state'),
                LogicException::class,
                'Database driver [http] does not support physical session statements.',
            ],
            [
                static fn () => $connection->beginTransaction(),
                LogicException::class,
                'Database driver [http] does not support transactions.',
            ],
        ] as [$operation, $exceptionClass, $message]) {
            $exception = null;

            try {
                $operation();
            } catch (LogicException|RuntimeException $thrown) {
                $exception = $thrown;
            }

            $this->assertInstanceOf($exceptionClass, $exception);
            $this->assertSame($message, $exception->getMessage());
        }
    }

    public function testNeutralConnectionOwnsEscapingAndLifecycleHooks(): void
    {
        $connection = new NeutralConnectionForTest(
            'analytics',
            '',
            ['name' => 'analytics', 'driver' => 'http']
        );

        $this->assertSame('HTTP_STRING[value]', $connection->escape('value'));
        $this->assertSame('null', $connection->escape(null));
        $this->assertSame('1', $connection->escape(true));
        $this->assertTrue($connection->ping());
        $this->assertTrue($connection->isReusable());

        $connection->disconnect();

        $this->assertSame(1, $connection->disconnectCalls);
        $this->assertSame(1, $connection->forgetCalls);
        $this->assertFalse($connection->driverResourcesPresent);

        $reconnects = 0;
        $connection->setReconnector(function (NeutralConnectionForTest $connection) use (&$reconnects): void {
            ++$reconnects;
            $connection->driverResourcesPresent = true;
        });
        $connection->reconnectIfMissingConnection();

        $this->assertSame(1, $reconnects);
        $this->assertTrue($connection->driverResourcesPresent);
    }

    public function testNeutralConnectionRefreshesOnlyFromTheSameConfiguredType(): void
    {
        $connection = new NeutralConnectionForTest(
            'analytics',
            'analytics_',
            ['name' => 'analytics', 'driver' => 'http']
        );
        $fresh = new NeutralConnectionForTest(
            'fresh_analytics',
            'fresh_',
            ['name' => 'analytics', 'driver' => 'http']
        );
        $fresh->driverGeneration = 'fresh';

        $connection->refreshFrom($fresh);

        $this->assertSame(1, $connection->replaceCalls);
        $this->assertSame(1, $connection->disconnectCalls);
        $this->assertSame(1, $connection->forgetCalls);
        $this->assertTrue($connection->driverResourcesPresent);
        $this->assertSame('fresh', $connection->driverGeneration);

        $connection->setDatabaseName('tenant_analytics');
        $connection->setTablePrefix('tenant_');
        $connection->resetForPool();

        $this->assertSame('fresh_analytics', $connection->getDatabaseName());
        $this->assertSame('fresh_', $connection->getTablePrefix());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Cannot refresh connection [analytics] of type [' . NeutralConnectionForTest::class
            . '] from connection [analytics] of type [' . NeutralTransactionConnectionForTest::class . '].'
        );

        $connection->refreshFrom(new NeutralTransactionConnectionForTest(
            'analytics',
            '',
            ['name' => 'analytics', 'driver' => 'http']
        ));
    }

    public function testNeutralPoolResetRestoresConfiguredMetadataAndRouting(): void
    {
        $connection = new NeutralConnectionForTest(
            'derived_analytics',
            'derived_',
            ['name' => 'analytics', 'driver' => 'http']
        );
        $connection->setDatabaseName('tenant_analytics');
        $connection->setTablePrefix('tenant_');
        $connection->setLatestReadWriteTypeForTest('write');

        $connection->resetForPool();

        $this->assertSame('derived_analytics', $connection->getDatabaseName());
        $this->assertSame('derived_', $connection->getTablePrefix());
        $this->assertNull($connection->latestReadWriteTypeForTest());

        $writeConnection = new NeutralConnectionForTest(
            'analytics',
            '',
            [
                'name' => 'analytics',
                'driver' => 'http',
                Connection::READ_WRITE_TYPE_CONFIG_KEY => 'write',
            ]
        );
        $writeConnection->setLatestReadWriteTypeForTest('read');

        $writeConnection->resetForPool();

        $this->assertSame('write', $writeConnection->latestReadWriteTypeForTest());
    }

    public function testNeutralNestedConcurrencyFailureInvalidatesOnceWithoutRollingBackTheDriver(): void
    {
        $connection = new NeutralTransactionConnectionForTest(
            'analytics',
            '',
            ['name' => 'analytics', 'driver' => 'http']
        );
        $connection->beginTransaction();
        $failure = new QueryException(
            'analytics',
            '',
            [],
            new RuntimeException('Deadlock found when trying to get lock')
        );

        try {
            $connection->transaction(static fn () => throw $failure);
            $this->fail('Expected the nested transaction to deadlock.');
        } catch (DeadlockException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
        }

        $this->assertSame(1, $connection->invalidateCalls);
        $this->assertSame(0, $connection->rollBackCalls);
        $this->assertSame(1, $connection->transactionLevel());

        $connection->rollBack();
    }

    public function testSettingDefaultCallsGetDefaultGrammar()
    {
        $connection = $this->getMockConnection(['getDefaultQueryGrammar']);
        $mock = m::mock(Grammar::class);
        $connection->expects($this->once())->method('getDefaultQueryGrammar')->willReturn($mock);
        $connection->useDefaultQueryGrammar();
        $this->assertEquals($mock, $connection->getQueryGrammar());
    }

    public function testSettingDefaultCallsGetDefaultPostProcessor()
    {
        $connection = $this->getMockConnection(['getDefaultPostProcessor']);
        $mock = m::mock(Processor::class);
        $connection->expects($this->once())->method('getDefaultPostProcessor')->willReturn($mock);
        $connection->useDefaultPostProcessor();
        $this->assertEquals($mock, $connection->getPostProcessor());
    }

    public function testSelectOneCallsSelectAndReturnsSingleResult()
    {
        $connection = $this->getMockConnection(['select']);
        $connection->expects($this->once())->method('select')->with('foo', ['bar' => 'baz'])->willReturn(['foo']);
        $this->assertSame('foo', $connection->selectOne('foo', ['bar' => 'baz']));
    }

    public function testScalarCallsSelectOneAndReturnsSingleResult()
    {
        $connection = $this->getMockConnection(['selectOne']);
        $connection->expects($this->once())->method('selectOne')->with('select count(*) from tbl')->willReturn((object) ['count(*)' => 5]);
        $this->assertSame(5, $connection->scalar('select count(*) from tbl'));
    }

    public function testScalarThrowsExceptionIfMultipleColumnsAreSelected()
    {
        $connection = $this->getMockConnection(['selectOne']);
        $connection->expects($this->once())->method('selectOne')->with('select a, b from tbl')->willReturn((object) ['a' => 'a', 'b' => 'b']);
        $this->expectException(MultipleColumnsSelectedException::class);
        $connection->scalar('select a, b from tbl');
    }

    public function testScalarReturnsNullIfUnderlyingSelectReturnsNoRows()
    {
        $connection = $this->getMockConnection(['selectOne']);
        $connection->expects($this->once())->method('selectOne')->with('select foo from tbl where 0=1')->willReturn(null);
        $this->assertNull($connection->scalar('select foo from tbl where 0=1'));
    }

    public function testInsertCallsTheStatementMethod()
    {
        $connection = $this->getMockConnection(['statement']);
        $connection->expects($this->once())->method('statement')->with($this->equalTo('foo'), $this->equalTo(['bar']))->willReturn(true);
        $results = $connection->insert('foo', ['bar']);
        $this->assertTrue($results);
    }

    public function testUpdateCallsTheAffectingStatementMethod()
    {
        $connection = $this->getMockConnection(['affectingStatement']);
        $connection->expects($this->once())->method('affectingStatement')->with($this->equalTo('foo'), $this->equalTo(['bar']))->willReturn(42);
        $results = $connection->update('foo', ['bar']);
        $this->assertSame(42, $results);
    }

    public function testDeleteCallsTheAffectingStatementMethod()
    {
        $connection = $this->getMockConnection(['affectingStatement']);
        $connection->expects($this->once())->method('affectingStatement')->with($this->equalTo('foo'), $this->equalTo(['bar']))->willReturn(1);
        $results = $connection->delete('foo', ['bar']);
        $this->assertSame(1, $results);
    }

    public function testTransactionLevelNotIncrementedOnTransactionException()
    {
        $pdo = $this->createMock(PDOStub::class);
        $pdo->expects($this->once())->method('beginTransaction')->will($this->throwException(new Exception));
        $connection = $this->getMockConnection([], $pdo);
        try {
            $connection->beginTransaction();
        } catch (Exception) {
            $this->assertEquals(0, $connection->transactionLevel());
        }
    }

    public function testBeginTransactionMethodRetriesOnFailure()
    {
        $pdo = $this->createStub(PDOStub::class);
        $pdo->method('beginTransaction')
            ->willReturnOnConsecutiveCalls($this->throwException(new ErrorException('server has gone away')), true);
        $connection = $this->getMockConnection(['reconnect'], $pdo);
        $connection->expects($this->once())->method('reconnect');
        $connection->beginTransaction();
        $this->assertEquals(1, $connection->transactionLevel());
    }

    public function testBeginTransactionMethodReconnectsMissingConnection()
    {
        $connection = $this->getMockConnection([], new PDO('sqlite::memory:'));
        $connection->setReconnector(function ($connection) {
            $connection->setPdo($this->createStub(PDOStub::class));
        });
        $connection->disconnect();
        $connection->beginTransaction();
        $this->assertEquals(1, $connection->transactionLevel());
    }

    public function testBeginTransactionMethodNeverRetriesIfWithinTransaction()
    {
        $pdo = $this->createMock(PDOStub::class);
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('exec')->will($this->throwException(new Exception));
        $connection = $this->getMockConnection(['reconnect'], $pdo);
        $queryGrammar = $this->createMock(Grammar::class);
        $queryGrammar->expects($this->once())->method('compileSavepoint')->willReturn('trans1');
        $queryGrammar->expects($this->once())->method('supportsSavepoints')->willReturn(true);
        $connection->setQueryGrammar($queryGrammar);
        $connection->expects($this->never())->method('reconnect');
        $connection->beginTransaction();
        $this->assertEquals(1, $connection->transactionLevel());
        try {
            $connection->beginTransaction();
        } catch (Exception) {
            $this->assertEquals(1, $connection->transactionLevel());
        }
    }

    public function testDisconnectClearsTransactionManagerStateEvenWhenTheLogicalLevelIsZero(): void
    {
        $connection = $this->getSqliteTransactionConnection();
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $manager->begin('default', 1);
        $manager->begin('default', 2);
        $manager->stageTransactions('default', 2);

        $this->assertCount(1, $manager->getCommittedTransactions());
        $this->assertCount(1, $manager->getPendingTransactions());

        $connection->disconnect();

        $this->assertCount(0, $manager->getCommittedTransactions());
        $this->assertCount(0, $manager->getPendingTransactions());
        $this->assertNull($connection->getRawPdo());
    }

    public function testBeganTransactionFiresEventsIfSet()
    {
        $pdo = $this->createStub(PDOStub::class);
        $connection = $this->getMockConnection([], $pdo);
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldReceive('hasListeners')->once()->with(TransactionBeginning::class)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with(m::type(TransactionBeginning::class));
        $connection->beginTransaction();
    }

    public function testCommittedFiresEventsIfSet()
    {
        $pdo = $this->createStub(PDOStub::class);
        $connection = $this->getMockConnection([], $pdo);
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldReceive('hasListeners')->once()->with(TransactionCommitted::class)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with(m::type(TransactionCommitted::class));
        $connection->commit();
    }

    public function testCommittingFiresEventsIfSet()
    {
        $pdo = $this->createStub(PDOStub::class);
        $connection = $this->getMockConnection([], $pdo);
        $connection->beginTransaction();
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldReceive('hasListeners')->once()->with(TransactionCommitting::class)->andReturn(true);
        $events->shouldReceive('hasListeners')->once()->with(TransactionCommitted::class)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with(m::type(TransactionCommitting::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(TransactionCommitted::class));
        $connection->commit();
    }

    public function testRollBackedFiresEventsIfSet()
    {
        $pdo = $this->createStub(PDOStub::class);
        $connection = $this->getMockConnection([], $pdo);
        $connection->beginTransaction();
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldReceive('hasListeners')->once()->with(TransactionRolledBack::class)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with(m::type(TransactionRolledBack::class));
        $connection->rollBack();
    }

    public function testBeganTransactionSkipsDispatchWhenNoListenersAreRegistered()
    {
        $pdo = $this->createStub(PDOStub::class);
        $connection = $this->getMockConnection([], $pdo);
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldReceive('hasListeners')->once()->with(TransactionBeginning::class)->andReturn(false);
        $events->shouldNotReceive('dispatch');
        $connection->beginTransaction();
    }

    public function testRedundantRollBackFiresNoEvent()
    {
        $pdo = $this->createStub(PDOStub::class);
        $connection = $this->getMockConnection([], $pdo);
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldNotReceive('dispatch');
        $connection->rollBack();
    }

    public function testTransactionMethodRunsSuccessfully()
    {
        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['beginTransaction', 'commit'])->getMock();
        $mock = $this->getMockConnection([], $pdo);
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('commit');
        $result = $mock->transaction(function ($db) {
            return $db;
        });
        $this->assertEquals($mock, $result);
    }

    public function testTransactionRetriesOnCommitDeadlockAfterPhysicalRollback(): void
    {
        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['inTransaction', 'beginTransaction', 'commit', 'rollBack'])->getMock();
        $connection = $this->getMockConnection([], $pdo);
        $pdo->expects($this->exactly(2))->method('beginTransaction');
        $pdo->expects($this->exactly(2))->method('commit')->willReturnOnConsecutiveCalls(
            $this->throwException(new PDOExceptionStub('Serialization failure', '40001')),
            true
        );
        $pdo->expects($this->once())->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack');

        $result = $connection->transaction(static fn (): string => 'success', 2);

        $this->assertSame('success', $result);
    }

    public function testTransactionRetriesOnSerializationFailure()
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Serialization failure');

        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['inTransaction', 'beginTransaction', 'commit', 'rollBack'])->getMock();
        $mock = $this->getMockConnection([], $pdo);
        $pdo->expects($this->exactly(3))->method('inTransaction')->willReturn(true);
        $pdo->expects($this->exactly(3))->method('commit')->will($this->throwException(new PDOExceptionStub('Serialization failure', '40001')));
        $pdo->expects($this->exactly(3))->method('beginTransaction');
        $pdo->expects($this->exactly(3))->method('rollBack');
        $mock->transaction(function () {
        }, 3);
    }

    public function testTransactionMethodRetriesOnDeadlock()
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Deadlock found when trying to get lock (Connection: conn, SQL: )');

        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['inTransaction', 'beginTransaction', 'commit', 'rollBack'])->getMock();
        $mock = $this->getMockConnection([], $pdo);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->exactly(3))->method('beginTransaction');
        $pdo->expects($this->exactly(3))->method('rollBack');
        $pdo->expects($this->never())->method('commit');
        $mock->transaction(function () {
            throw new QueryException('conn', '', [], new Exception('Deadlock found when trying to get lock'));
        }, 3);
    }

    public function testTransactionMethodRollsbackAndThrows()
    {
        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['inTransaction', 'beginTransaction', 'commit', 'rollBack'])->getMock();
        $mock = $this->getMockConnection([], $pdo);
        // $pdo->expects($this->once())->method('inTransaction');
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('rollBack');
        $pdo->expects($this->never())->method('commit');
        try {
            $mock->transaction(function () {
                throw new Exception('foo');
            });
        } catch (Exception $e) {
            $this->assertSame('foo', $e->getMessage());
        }
    }

    public function testRunMethodRetriesOnFailure()
    {
        $method = (new ReflectionClass(Connection::class))->getMethod('run');

        $pdo = $this->createStub(PDOStub::class);
        $mock = $this->getMockConnection(['tryAgainIfCausedByLostConnection'], $pdo);
        $mock->expects($this->once())->method('tryAgainIfCausedByLostConnection');

        $method->invokeArgs($mock, ['', [], function () {
            throw new QueryException('', '', [], new Exception);
        }]);
    }

    public function testRunMethodPreservesQueryCancellationWithoutRetryOrLogging(): void
    {
        $method = (new ReflectionClass(Connection::class))->getMethod('run');
        $cancellation = new CanceledException('query canceled');
        $pdo = $this->createStub(PDOStub::class);
        $connection = $this->getMockConnection(['tryAgainIfCausedByLostConnection'], $pdo);
        $connection->expects($this->never())->method('tryAgainIfCausedByLostConnection');
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldNotReceive('hasListeners')->with(QueryFailed::class);
        $events->shouldNotReceive('dispatch')->with(m::type(QueryFailed::class));

        try {
            $method->invokeArgs($connection, ['select 1', [], fn () => throw $cancellation]);
            $this->fail('Expected the query cancellation to be thrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(0, $connection->getErrorCount());
        $this->assertSame([], $connection->getQueryLog());
    }

    public function testRunDispatchesQueryFailedWithTheFinalLogicalFailure(): void
    {
        $method = (new ReflectionClass(Connection::class))->getMethod('run');
        $connection = new PdoConnection(
            new PDOStub,
            'analytics',
            config: [
                'name' => 'analytics',
                'driver' => 'mysql',
                Connection::READ_WRITE_TYPE_CONFIG_KEY => 'read',
            ],
        );
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $event = null;
        $events->shouldReceive('hasListeners')->once()->with(QueryFailed::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->once()->withArgs(
            static function (QueryFailed $dispatched) use (&$event): bool {
                $event = $dispatched;

                return true;
            },
        );
        $exception = null;

        try {
            $method->invokeArgs($connection, [
                'select * from users where id = ?',
                [1],
                static fn (): never => throw new PDOException('Query failed.'),
            ]);
        } catch (QueryException $thrown) {
            $exception = $thrown;
        }

        $this->assertInstanceOf(QueryException::class, $exception);
        $this->assertInstanceOf(QueryFailed::class, $event);
        $this->assertSame('select * from users where id = ?', $event->sql);
        $this->assertSame([1], $event->bindings);
        $this->assertGreaterThanOrEqual(0.0, $event->time);
        $this->assertSame($connection, $event->connection);
        $this->assertSame('analytics', $event->connectionName);
        $this->assertSame('read', $event->readWriteType);
        $this->assertSame($exception, $event->exception);
    }

    public function testRunDispatchesTheReconnectorThrowableAsTheFinalFailure(): void
    {
        $method = (new ReflectionClass(Connection::class))->getMethod('run');
        $connection = $this->getMockConnection();
        $reconnectorException = new RuntimeException('Unable to reconnect.');
        $connection->setReconnector(static function (Connection $connection) use ($reconnectorException): never {
            throw $reconnectorException;
        });
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $event = null;
        $events->shouldReceive('hasListeners')->once()->with(QueryFailed::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->once()->withArgs(
            static function (QueryFailed $dispatched) use (&$event): bool {
                $event = $dispatched;

                return true;
            },
        );
        $exception = null;

        try {
            $method->invokeArgs($connection, [
                'select 1',
                [],
                static fn (): never => throw new PDOException('server has gone away'),
            ]);
        } catch (RuntimeException $thrown) {
            $exception = $thrown;
        }

        $this->assertSame($reconnectorException, $exception);
        $this->assertInstanceOf(QueryFailed::class, $event);
        $this->assertSame($reconnectorException, $event->exception);
    }

    public function testRunDispatchesLostConnectionExceptionWhenNoReconnectorExists(): void
    {
        $method = (new ReflectionClass(Connection::class))->getMethod('run');
        $connection = $this->getMockConnection();
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $event = null;
        $events->shouldReceive('hasListeners')->once()->with(QueryFailed::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->once()->withArgs(
            static function (QueryFailed $dispatched) use (&$event): bool {
                $event = $dispatched;

                return true;
            },
        );
        $exception = null;

        try {
            $method->invokeArgs($connection, [
                'select 1',
                [],
                static fn (): never => throw new PDOException('server has gone away'),
            ]);
        } catch (LostConnectionException $thrown) {
            $exception = $thrown;
        }

        $this->assertInstanceOf(LostConnectionException::class, $exception);
        $this->assertInstanceOf(QueryFailed::class, $event);
        $this->assertSame($exception, $event->exception);
    }

    public function testRunEmitsOnlyQueryExecutedWhenLostConnectionRetrySucceeds(): void
    {
        $method = (new ReflectionClass(Connection::class))->getMethod('run');
        $connection = $this->getMockConnection();
        $connection->setReconnector(static function (Connection $connection): void {
        });
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldReceive('hasListeners')->once()->with(QueryExecuted::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->once()->with(m::type(QueryExecuted::class));
        $events->shouldNotReceive('dispatch')->with(m::type(QueryFailed::class));
        $attempts = 0;

        $result = $method->invokeArgs($connection, [
            'select 1',
            [],
            static function () use (&$attempts): string {
                if (++$attempts === 1) {
                    throw new PDOException('server has gone away');
                }

                return 'retried';
            },
        ]);

        $this->assertSame('retried', $result);
        $this->assertSame(2, $attempts);
    }

    public function testRunSkipsQueryFailedDispatchWhenNoListenersAreRegistered(): void
    {
        $method = (new ReflectionClass(Connection::class))->getMethod('run');
        $connection = $this->getMockConnection();
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldReceive('hasListeners')->once()->with(QueryFailed::class)->andReturnFalse();
        $events->shouldNotReceive('dispatch');

        $this->expectException(QueryException::class);

        $method->invokeArgs($connection, [
            'select 1',
            [],
            static fn (): never => throw new PDOException('Query failed.'),
        ]);
    }

    public function testRunMethodNeverRetriesIfWithinTransaction()
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('(Connection: conn, SQL: ) (Connection: test, Host: , Port: , Database: , SQL: )');

        $method = (new ReflectionClass(Connection::class))->getMethod('run');

        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['beginTransaction'])->getMock();
        $mock = $this->getMockConnection(['tryAgainIfCausedByLostConnection'], $pdo);
        $pdo->expects($this->once())->method('beginTransaction');
        $mock->expects($this->never())->method('tryAgainIfCausedByLostConnection');
        $mock->beginTransaction();

        $method->invokeArgs($mock, ['', [], function () {
            throw new QueryException('conn', '', [], new Exception);
        }]);
    }

    public function testFromCreatesNewQueryBuilder()
    {
        $conn = $this->getMockConnection();
        $conn->setQueryGrammar(m::mock(Grammar::class));
        $conn->setPostProcessor(m::mock(Processor::class));
        $builder = $conn->table('users');
        $this->assertInstanceOf(BaseBuilder::class, $builder);
        $this->assertSame('users', $builder->from);
    }

    public function testTableNormalizesIntegerBackedEnumName(): void
    {
        $connection = $this->getMockConnection();
        $connection->setQueryGrammar(m::mock(Grammar::class));
        $connection->setPostProcessor(m::mock(Processor::class));

        $builder = $connection->table(DatabaseTableName::Zero);

        $this->assertSame('0', $builder->from);
    }

    public function testPrepareBindings()
    {
        $date = m::mock(DateTime::class);
        $date->shouldReceive('format')->once()->with('foo')->andReturn('bar');
        $bindings = ['test' => $date];
        $conn = $this->getMockConnection();
        $grammar = m::mock(Grammar::class);
        $grammar->shouldReceive('getDateFormat')->once()->andReturn('foo');
        $conn->setQueryGrammar($grammar);
        $result = $conn->prepareBindings($bindings);
        $this->assertEquals(['test' => 'bar'], $result);
    }

    public function testLogQueryFiresEventsIfSet()
    {
        $connection = $this->getMockConnection();
        $connection->logQuery('foo', [], time());
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldReceive('hasListeners')->once()->with(QueryExecuted::class)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with(m::type(QueryExecuted::class));
        $connection->logQuery('foo', [], null);
    }

    public function testLogQuerySkipsDispatchWhenNoListenersAreRegistered()
    {
        $connection = $this->getMockConnection();
        $connection->setEventDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldReceive('hasListeners')->once()->with(QueryExecuted::class)->andReturn(false);
        $events->shouldNotReceive('dispatch');
        $connection->logQuery('foo', [], null);
    }

    public function testBeforeExecutingHooksCanBeRegistered()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The callback was fired');

        $connection = $this->getMockConnection();
        $connection->beforeExecuting(function () {
            throw new Exception('The callback was fired');
        });
        $connection->select('foo bar', ['baz']);
    }

    public function testBeforeStartingTransactionHooksCanBeRegistered()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The callback was fired');

        $connection = $this->getMockConnection();
        $connection->beforeStartingTransaction(function () {
            throw new Exception('The callback was fired');
        });
        $connection->beginTransaction();
    }

    public function testBeginPublicationFailureRollsBackAndPreservesTheOriginalFailure(): void
    {
        $connection = $this->getSqliteTransactionConnection();
        $publicationFailure = new RuntimeException('manager begin failure');
        $cleanupFailure = new RuntimeException('manager rollback failure');
        $manager = m::mock(DatabaseTransactionsManager::class);
        $manager->shouldReceive('begin')->once()->with('default', 1)->andThrow($publicationFailure);
        $manager->shouldReceive('rollback')->once()->with('default', 0)->andThrow($cleanupFailure);
        $connection->setTransactionManager($manager);

        try {
            $connection->beginTransaction();
            $this->fail('Expected transaction publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($publicationFailure, $exception);
        }

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    public function testBeganEventFailureRollsBackPublishedTransactionState(): void
    {
        $connection = $this->getSqliteTransactionConnection();
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $failure = new RuntimeException('began listener failure');
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(TransactionBeginning::class)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with(m::type(TransactionBeginning::class))->andThrow($failure);
        $events->shouldReceive('hasListeners')->once()->with(TransactionRolledBack::class)->andReturn(false);
        $connection->setEventDispatcher($events);

        try {
            $connection->beginTransaction();
            $this->fail('Expected the transaction event to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertCount(0, $manager->getPendingTransactions());
    }

    public function testCommittingEventFailureRollsBackManagedTransaction(): void
    {
        $connection = $this->getSqliteTransactionConnection();
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $failure = new RuntimeException('committing listener failure');
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(TransactionBeginning::class)->andReturn(false);
        $events->shouldReceive('hasListeners')->once()->with(TransactionCommitting::class)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with(m::type(TransactionCommitting::class))->andThrow($failure);
        $events->shouldReceive('hasListeners')->once()->with(TransactionRolledBack::class)->andReturn(false);
        $connection->setEventDispatcher($events);

        try {
            $connection->transaction(static fn (): null => null);
            $this->fail('Expected the committing event to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertCount(0, $manager->getPendingTransactions());
    }

    public function testManagedTransactionFailureRemainsPrimaryWhenRollbackCleanupFails(): void
    {
        $connection = $this->getSqliteTransactionConnection();
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $failure = new RuntimeException('transaction callback failure');
        $cleanupFailure = new RuntimeException('rollback callback failure');
        $cleanupCalls = 0;

        try {
            $connection->transaction(function (Connection $connection) use (
                $failure,
                $cleanupFailure,
                &$cleanupCalls
            ): never {
                $connection->afterRollBack(function () use ($cleanupFailure, &$cleanupCalls): never {
                    ++$cleanupCalls;

                    throw $cleanupFailure;
                });

                throw $failure;
            });
            $this->fail('Expected the transaction callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, $cleanupCalls);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertCount(0, $manager->getPendingTransactions());
        $this->assertCount(0, $manager->getCommittedTransactions());
    }

    public function testManagedTransactionDoesNotRetryWhenRollbackCleanupFails(): void
    {
        $connection = $this->getSqliteTransactionConnection();
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $failure = new QueryException(
            'default',
            '',
            [],
            new RuntimeException('Deadlock found when trying to get lock')
        );
        $cleanupFailure = new RuntimeException('rollback callback failure');
        $callbackCalls = 0;

        try {
            $connection->transaction(function (Connection $connection) use (
                $failure,
                $cleanupFailure,
                &$callbackCalls
            ): never {
                ++$callbackCalls;

                $connection->afterRollBack(static function () use ($cleanupFailure): never {
                    throw $cleanupFailure;
                });

                throw $failure;
            }, 2);
            $this->fail('Expected the transaction callback to fail.');
        } catch (QueryException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, $callbackCalls);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertCount(0, $manager->getPendingTransactions());
        $this->assertCount(0, $manager->getCommittedTransactions());
    }

    public function testNestedDeadlockFailureRemainsPrimaryWhenRollbackCleanupFails(): void
    {
        $connection = $this->getSqliteTransactionConnection();
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $connection->beginTransaction();
        $failure = new QueryException(
            'default',
            '',
            [],
            new RuntimeException('Deadlock found when trying to get lock')
        );
        $cleanupFailure = new RuntimeException('rollback callback failure');
        $cleanupCalls = 0;

        try {
            $connection->transaction(function (Connection $connection) use (
                $failure,
                $cleanupFailure,
                &$cleanupCalls
            ): never {
                $connection->afterRollBack(function () use ($cleanupFailure, &$cleanupCalls): never {
                    ++$cleanupCalls;

                    throw $cleanupFailure;
                });

                throw $failure;
            });
            $this->fail('Expected the nested transaction to deadlock.');
        } catch (DeadlockException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
        }

        $this->assertSame(1, $cleanupCalls);
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertTrue($connection->getPdo()->inTransaction());
        $this->assertCount(1, $manager->getPendingTransactions());
        $this->assertCount(0, $manager->getCommittedTransactions());

        $connection->rollBack();
    }

    public function testExplicitCommittingEventFailureLeavesTheTransactionCallerOwned(): void
    {
        $connection = $this->getSqliteTransactionConnection();
        $connection->beginTransaction();
        $failure = new RuntimeException('committing listener failure');
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(TransactionCommitting::class)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with(m::type(TransactionCommitting::class))->andThrow($failure);
        $connection->setEventDispatcher($events);

        try {
            $connection->commit();
            $this->fail('Expected the committing event to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, $connection->transactionLevel());
        $this->assertTrue($connection->getPdo()->inTransaction());

        $connection->unsetEventDispatcher();
        $connection->rollBack();
    }

    public function testManagerCommitFailureStillDispatchesCommittedEventAfterPhysicalCommit(): void
    {
        $connection = $this->getSqliteTransactionConnection();
        $failure = new RuntimeException('after commit callback failure');
        $manager = m::mock(DatabaseTransactionsManager::class);
        $manager->shouldReceive('begin')->once()->with('default', 1);
        $manager->shouldReceive('commit')->once()->with('default', 1, 0)->andThrow($failure);
        $connection->setTransactionManager($manager);
        $connection->beginTransaction();

        $eventDispatched = false;
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(TransactionCommitting::class)->andReturn(false);
        $events->shouldReceive('hasListeners')->once()->with(TransactionCommitted::class)->andReturn(true);
        $events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(TransactionCommitted::class))
            ->andReturnUsing(function () use (&$eventDispatched): void {
                $eventDispatched = true;
            });
        $connection->setEventDispatcher($events);

        try {
            $connection->commit();
            $this->fail('Expected transaction-manager commit to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertTrue($eventDispatched);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    public function testCommitFailureDoesNotRetryWhenRollbackCleanupFails(): void
    {
        $commitFailure = new PDOExceptionStub('Serialization failure', '40001');
        $cleanupFailure = new RuntimeException('rollback callback failure');
        $pdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['beginTransaction', 'commit', 'inTransaction', 'rollBack'])
            ->getMock();
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('commit')->willThrowException($commitFailure);
        $pdo->expects($this->once())->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack');

        $connection = $this->getMockConnection([], $pdo);
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $cleanupCalls = 0;

        try {
            $connection->transaction(function (Connection $connection) use (&$cleanupCalls, $cleanupFailure): void {
                $connection->afterRollBack(function () use (&$cleanupCalls, $cleanupFailure): never {
                    ++$cleanupCalls;

                    throw $cleanupFailure;
                });
            }, 2);
            $this->fail('Expected the physical commit to fail.');
        } catch (PDOException $exception) {
            $this->assertSame($commitFailure, $exception);
        }

        $this->assertSame(1, $cleanupCalls);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertCount(0, $manager->getPendingTransactions());
        $this->assertCount(0, $manager->getCommittedTransactions());
    }

    public function testManagerRollbackFailureStillDispatchesRolledBackEvent(): void
    {
        $connection = $this->getSqliteTransactionConnection();
        $failure = new RuntimeException('manager rollback failure');
        $manager = m::mock(DatabaseTransactionsManager::class);
        $manager->shouldReceive('begin')->once()->with('default', 1);
        $manager->shouldReceive('rollback')->once()->with('default', 0)->andThrow($failure);
        $connection->setTransactionManager($manager);
        $connection->beginTransaction();

        $eventDispatched = false;
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(TransactionRolledBack::class)->andReturn(true);
        $events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(TransactionRolledBack::class))
            ->andReturnUsing(function () use (&$eventDispatched): void {
                $eventDispatched = true;
            });
        $connection->setEventDispatcher($events);

        try {
            $connection->rollBack();
            $this->fail('Expected transaction-manager rollback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertTrue($eventDispatched);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    public function testRolledBackEventFailureOccursAfterManagerCleanup(): void
    {
        $connection = $this->getSqliteTransactionConnection();
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $connection->beginTransaction();
        $failure = new RuntimeException('rolled back listener failure');
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(TransactionRolledBack::class)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with(m::type(TransactionRolledBack::class))->andThrow($failure);
        $connection->setEventDispatcher($events);

        try {
            $connection->rollBack();
            $this->fail('Expected the rolled back event to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertCount(0, $manager->getPendingTransactions());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    public function testPretendOnlyLogsQueries()
    {
        $connection = $this->getMockConnection();
        $grammar = m::mock(Grammar::class);
        $grammar->shouldReceive('substituteBindingsIntoRawSql')->andReturnUsing(fn ($query) => $query);
        $connection->setQueryGrammar($grammar);
        $queries = $connection->pretend(function ($connection) {
            $connection->select('foo bar', ['baz']);
        });
        $this->assertSame('foo bar', $queries[0]['query']);
        $this->assertEquals(['baz'], $queries[0]['bindings']);
    }

    public function testPretendRestoresThePreviousQueryLoggingState(): void
    {
        $connection = $this->getMockConnection();
        $connection->disableQueryLog();

        $connection->pretend(static function (): void {
        });

        $this->assertFalse($connection->logging());

        $connection->enableQueryLog();
        $connection->pretend(static function (): void {
        });

        $this->assertTrue($connection->logging());
    }

    public function testPretendRestoresQueryLoggingWhenTheCallbackFails(): void
    {
        $connection = $this->getMockConnection();
        $connection->disableQueryLog();
        $failure = new RuntimeException('pretend failure');

        try {
            $connection->pretend(static function () use ($failure): never {
                throw $failure;
            });
            $this->fail('Expected the pretend callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertFalse($connection->logging());
        $this->assertFalse($connection->pretending());
    }

    public function testSchemaBuilderCanBeCreated()
    {
        $connection = $this->getMockConnection();
        $schema = $connection->getSchemaBuilder();
        $this->assertInstanceOf(Builder::class, $schema);
        $this->assertSame($connection, $schema->getConnection());
    }

    public function testGetRawQueryLog()
    {
        $mock = $this->getMockConnection(['getQueryLog']);
        $mock->expects($this->once())->method('getQueryLog')->willReturn([
            [
                'query' => 'select * from tbl where col = ?',
                'bindings' => [
                    0 => 'foo',
                ],
                'time' => 1.23,
            ],
        ]);

        $queryGrammar = $this->createMock(Grammar::class);
        $queryGrammar->expects($this->once())
            ->method('substituteBindingsIntoRawSql')
            ->with('select * from tbl where col = ?', ['foo'])
            ->willReturn("select * from tbl where col = 'foo'");
        $mock->setQueryGrammar($queryGrammar);

        $log = $mock->getRawQueryLog();

        $this->assertEquals("select * from tbl where col = 'foo'", $log[0]['raw_query']);
        $this->assertEquals(1.23, $log[0]['time']);
    }

    public function testStickyReadConnectionsUseWritePdoAfterRecordsModified(): void
    {
        [$connection, $writePdo, $readPdo] = $this->getReadWriteConnection(sticky: true);

        $this->assertSame($readPdo, $connection->getReadPdo());

        $connection->recordsHaveBeenModified();

        $this->assertSame($writePdo, $connection->getReadPdo());
    }

    public function testNonStickyReadConnectionsKeepUsingReadPdoAfterRecordsModified(): void
    {
        [$connection, $writePdo, $readPdo] = $this->getReadWriteConnection(sticky: false);

        $this->assertSame($readPdo, $connection->getReadPdo());

        $connection->recordsHaveBeenModified();

        $actualReadPdo = $connection->getReadPdo();

        $this->assertSame($readPdo, $actualReadPdo);
        $this->assertNotSame($writePdo, $actualReadPdo);
    }

    public function testRecordModificationStateCanBeReplacedFluently(): void
    {
        $connection = $this->getMockConnection();

        $this->assertSame($connection, $connection->setRecordModificationState(true));
        $this->assertTrue($connection->hasModifiedRecords());

        $this->assertSame($connection, $connection->setRecordModificationState(false));
        $this->assertFalse($connection->hasModifiedRecords());
    }

    public function testResetForPoolClearsStickyReadRoutingState(): void
    {
        [$connection, $writePdo, $readPdo] = $this->getReadWriteConnection(sticky: true);

        $connection->recordsHaveBeenModified();
        $this->assertSame($writePdo, $connection->getReadPdo());

        $connection->resetForPool();

        $this->assertSame($readPdo, $connection->getReadPdo());
    }

    public function testForeignKeyConstraintSuppressionDepthIsConnectionOwned(): void
    {
        $connection = $this->getMockConnection();

        $this->assertTrue($connection->beginForeignKeyConstraintSuppression());
        $this->assertFalse($connection->beginForeignKeyConstraintSuppression());

        $connection->endForeignKeyConstraintSuppression();
        $connection->endForeignKeyConstraintSuppression();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No foreign key constraint suppression scope is active.');

        $connection->endForeignKeyConstraintSuppression();
    }

    public function testQueryExceptionContainsReadConnectionDetailsWhenUsingReadPdo()
    {
        // Create write PDO mock that will NOT be used for this query
        $writePdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['prepare'])
            ->getMock();
        $writePdo->expects($this->never())->method('prepare');

        // Create read PDO mock that throws an exception
        $readPdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['prepare'])
            ->getMock();
        $readPdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Connection refused'));

        // Write configuration (passed to constructor)
        $writeConfig = [
            'driver' => 'mysql',
            'name' => 'mysql',
            'host' => '192.168.1.10',
            'port' => '3306',
            'database' => 'write_db',
        ];

        // Create connection with write config
        $connection = new PdoConnection($writePdo, 'write_db', '', $writeConfig);
        $connection->useDefaultQueryGrammar();
        $connection->useDefaultPostProcessor();

        // Read configuration (different from write)
        $readConfig = [
            'host' => '192.168.1.20',
            'port' => '3307',
            'database' => 'read_db',
        ];

        // Set read PDO and its config
        $connection->setReadPdo($readPdo);
        $connection->setReadPdoConfig($readConfig);

        try {
            $connection->select('SELECT * FROM users', useReadPdo: true);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            // Verify the readWriteType is correctly set to 'read'
            $this->assertSame('read', $e->readWriteType);

            // Verify connection details show READ config, not write config
            $connectionDetails = $e->getConnectionDetails();
            $this->assertSame('192.168.1.20', $connectionDetails['host']);
            $this->assertSame('3307', $connectionDetails['port']);
            $this->assertSame('read_db', $connectionDetails['database']);
        }
    }

    public function testQueryExceptionContainsReadConnectionDetailsWhenReadPdoConnectionFails()
    {
        // Write PDO (won't be used)
        $writePdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['prepare'])
            ->getMock();
        $writePdo->expects($this->never())->method('prepare');

        // Write configuration
        $writeConfig = [
            'driver' => 'mysql',
            'name' => 'mysql',
            'host' => '192.168.1.10',
            'port' => '3306',
            'database' => 'write_db',
        ];

        $connection = new PdoConnection($writePdo, 'write_db', '', $writeConfig);
        $connection->useDefaultQueryGrammar();
        $connection->useDefaultPostProcessor();

        // Read config (different host)
        $readConfig = [
            'host' => '192.168.1.20',
            'port' => '3307',
            'database' => 'read_db',
        ];

        // Simulate lazy PDO that fails during connection (e.g., SET NAMES fails)
        $connection->setReadPdo(function () {
            throw new PDOException('SQLSTATE[HY000] SET NAMES failed');
        });
        $connection->setReadPdoConfig($readConfig);

        try {
            $connection->select('SELECT * FROM users', useReadPdo: true);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('read', $e->readWriteType);

            // Verify connection details show READ config even for connection-time failures
            $connectionDetails = $e->getConnectionDetails();
            $this->assertSame('192.168.1.20', $connectionDetails['host']);
            $this->assertSame('3307', $connectionDetails['port']);
            $this->assertSame('read_db', $connectionDetails['database']);
        }
    }

    public function testQueryExceptionContainsDerivedReadConnectionDetails(): void
    {
        $pdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['prepare'])
            ->getMock();
        $pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Connection refused'));

        $connection = new PdoConnection($pdo, 'read_db', '', [
            'driver' => 'mysql',
            'name' => 'mysql',
            'host' => '192.168.1.20',
            'port' => '3307',
            'database' => 'read_db',
            Connection::READ_WRITE_TYPE_CONFIG_KEY => 'read',
        ]);
        $connection->useDefaultQueryGrammar();
        $connection->useDefaultPostProcessor();

        try {
            $connection->select('SELECT * FROM users', useReadPdo: true);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('read', $e->readWriteType);

            $connectionDetails = $e->getConnectionDetails();
            $this->assertSame('192.168.1.20', $connectionDetails['host']);
            $this->assertSame('3307', $connectionDetails['port']);
            $this->assertSame('read_db', $connectionDetails['database']);
        }
    }

    public function testQueryExceptionContainsWriteConnectionDetailsWhenUsingWritePdo()
    {
        // Create write PDO mock that throws an exception
        $writePdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['prepare'])
            ->getMock();
        $writePdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Connection refused'));

        // Create read PDO mock that will NOT be used
        $readPdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['prepare'])
            ->getMock();
        $readPdo->expects($this->never())->method('prepare');

        // Write configuration (passed to constructor)
        $writeConfig = [
            'driver' => 'mysql',
            'name' => 'mysql',
            'host' => '192.168.1.10',
            'port' => '3306',
            'database' => 'write_db',
        ];

        $connection = new PdoConnection($writePdo, 'write_db', '', $writeConfig);
        $connection->useDefaultQueryGrammar();
        $connection->useDefaultPostProcessor();

        // Read configuration (different from write)
        $readConfig = [
            'host' => '192.168.1.20',
            'port' => '3307',
            'database' => 'read_db',
        ];

        $connection->setReadPdo($readPdo);
        $connection->setReadPdoConfig($readConfig);

        try {
            $connection->select('SELECT * FROM users', useReadPdo: false);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            // Verify the readWriteType is correctly set to 'write'
            $this->assertSame('write', $e->readWriteType);

            // Verify connection details show WRITE config, not read config
            $connectionDetails = $e->getConnectionDetails();
            $this->assertSame('192.168.1.10', $connectionDetails['host']);
            $this->assertSame('3306', $connectionDetails['port']);
            $this->assertSame('write_db', $connectionDetails['database']);
        }
    }

    public function testQueryExceptionContainsWriteConnectionDetailsWhenWritePdoConnectionFails()
    {
        // Write configuration
        $writeConfig = [
            'driver' => 'mysql',
            'name' => 'mysql',
            'host' => '192.168.1.10',
            'port' => '3306',
            'database' => 'write_db',
        ];

        // Simulate lazy write PDO that fails during connection (e.g., SET NAMES fails)
        $connection = new PdoConnection(function () {
            throw new PDOException('SQLSTATE[HY000] SET NAMES failed');
        }, 'write_db', '', $writeConfig);
        $connection->useDefaultQueryGrammar();
        $connection->useDefaultPostProcessor();

        // Read config (different host)
        $readConfig = [
            'host' => '192.168.1.20',
            'port' => '3307',
            'database' => 'read_db',
        ];

        $connection->setReadPdo(new PDOStub);
        $connection->setReadPdoConfig($readConfig);

        try {
            $connection->select('SELECT * FROM users', useReadPdo: false);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('write', $e->readWriteType);

            // Verify connection details show WRITE config even for connection-time failures
            $connectionDetails = $e->getConnectionDetails();
            $this->assertSame('192.168.1.10', $connectionDetails['host']);
            $this->assertSame('3306', $connectionDetails['port']);
            $this->assertSame('write_db', $connectionDetails['database']);
        }
    }

    protected function getSqliteTransactionConnection(): PdoConnection
    {
        return new PdoConnection(
            new PDO('sqlite::memory:'),
            ':memory:',
            '',
            ['name' => 'default', 'driver' => 'sqlite']
        );
    }

    /**
     * Create a read / write connection for sticky routing assertions.
     *
     * @return array{0: PdoConnection, 1: PDOStub, 2: PDOStub}
     */
    protected function getReadWriteConnection(bool $sticky): array
    {
        $writePdo = new PDOStub;
        $readPdo = new PDOStub;

        $connection = new PdoConnection($writePdo, 'test_db', '', [
            'name' => 'test',
            'driver' => 'mysql',
            'sticky' => $sticky,
        ]);
        $connection->setReadPdo($readPdo);

        return [$connection, $writePdo, $readPdo];
    }

    protected function getMockConnection($methods = [], $pdo = null)
    {
        $pdo = $pdo ?: new PDOStub;

        if ($methods === []) {
            $connection = new PdoConnection($pdo, 'test_db', '', ['name' => 'test', 'driver' => 'mysql']);
            $connection->setSchemaGrammar(m::mock(SchemaGrammar::class));
            $connection->enableQueryLog();

            return $connection;
        }

        $defaults = ['getDefaultQueryGrammar', 'getDefaultPostProcessor', 'getDefaultSchemaGrammar'];
        $connection = $this->getMockBuilder(PdoConnection::class)->onlyMethods(array_values(array_unique(array_merge($defaults, $methods))))->setConstructorArgs([$pdo, 'test_db', '', ['name' => 'test', 'driver' => 'mysql']])->getMock();
        $connection->method('getDefaultSchemaGrammar')->willReturn(m::mock(SchemaGrammar::class));
        $connection->enableQueryLog();

        return $connection;
    }
}

enum DatabaseTableName: int
{
    case Zero = 0;
}

class PDOStub extends PDO
{
    public function __construct()
    {
    }
}

class PDOExceptionStub extends PDOException
{
    /**
     * Overrides Exception::__construct, which casts $code to integer, so that we can create
     * an exception with a string $code consistent with the real PDOException behavior.
     *
     * @param null|string $message
     * @param null|string $code
     */
    public function __construct($message = null, $code = null)
    {
        $this->message = $message;
        $this->code = $code;
    }
}

class NeutralConnectionForTest extends Connection
{
    public bool $driverResourcesPresent = true;

    public int $disconnectCalls = 0;

    public int $forgetCalls = 0;

    public int $replaceCalls = 0;

    public string $driverGeneration = 'initial';

    /**
     * Set the latest read / write type for testing.
     */
    public function setLatestReadWriteTypeForTest(?string $type): void
    {
        $this->latestReadWriteTypeRetrieved = $type;
    }

    /**
     * Get the effective read / write type for testing.
     */
    public function latestReadWriteTypeForTest(): ?string
    {
        return $this->latestReadWriteTypeUsed();
    }

    public function select(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): array
    {
        return [];
    }

    public function cursor(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): Generator
    {
        yield from [];
    }

    public function statement(string $query, array $bindings = []): bool
    {
        return true;
    }

    public function affectingStatement(string $query, array $bindings = []): int
    {
        return 0;
    }

    public function unprepared(string $query): bool
    {
        return true;
    }

    public function ping(): bool
    {
        return true;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function getServerVersion(): string
    {
        return '1.0';
    }

    protected function getDefaultDriverName(): string
    {
        return 'http';
    }

    protected function escapeString(string $value): string
    {
        return "HTTP_STRING[{$value}]";
    }

    protected function hasDriverResources(): bool
    {
        return $this->driverResourcesPresent;
    }

    protected function disconnectDriverResources(): void
    {
        ++$this->disconnectCalls;
        $this->forgetDriverResources();
    }

    protected function forgetDriverResources(): void
    {
        ++$this->forgetCalls;
        $this->driverResourcesPresent = false;
    }

    protected function replaceDriverResources(Connection $fresh): void
    {
        ++$this->replaceCalls;

        /** @var self $fresh */
        $driverResourcesPresent = $fresh->driverResourcesPresent;
        $driverGeneration = $fresh->driverGeneration;
        $database = $fresh->database;
        $configuredDatabase = $fresh->configuredDatabase;
        $tablePrefix = $fresh->tablePrefix;
        $configuredTablePrefix = $fresh->configuredTablePrefix;
        $config = $fresh->config;
        $readConnectionConfig = $fresh->readConnectionConfig;
        $readWriteType = $fresh->readWriteType;

        try {
            $this->disconnectDriverResources();
        } finally {
            $this->driverResourcesPresent = $driverResourcesPresent;
            $this->driverGeneration = $driverGeneration;
            $this->database = $database;
            $this->configuredDatabase = $configuredDatabase;
            $this->tablePrefix = $tablePrefix;
            $this->configuredTablePrefix = $configuredTablePrefix;
            $this->config = $config;
            $this->readConnectionConfig = $readConnectionConfig;
            $this->readWriteType = $readWriteType;
            $this->latestReadWriteTypeRetrieved = null;
        }
    }
}

class NeutralTransactionConnectionForTest extends NeutralConnectionForTest
{
    public int $invalidateCalls = 0;

    public int $rollBackCalls = 0;

    public bool $physicalTransaction = false;

    public function inTransaction(): bool
    {
        return $this->physicalTransaction;
    }

    protected function invalidateCurrentSessionState(): void
    {
        ++$this->invalidateCalls;
    }

    protected function executeBeginTransactionStatement(): void
    {
        $this->physicalTransaction = true;
    }

    protected function createSavepoint(): void
    {
    }

    protected function performCommit(): void
    {
        $this->physicalTransaction = false;
    }

    protected function performRollBack(int $toLevel): void
    {
        ++$this->rollBackCalls;
        $this->physicalTransaction = false;
    }
}
