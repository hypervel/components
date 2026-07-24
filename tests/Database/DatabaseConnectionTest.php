<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseConnectionTest;

use DateTime;
use ErrorException;
use Exception;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\DeadlockException;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionCommitting;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Database\MultipleColumnsSelectedException;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\Query\Builder as BaseBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Builder;
use Hypervel\Database\Schema\Grammars\Grammar as SchemaGrammar;
use Hypervel\Database\SessionConfigurator;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PDO;
use PDOException;
use PDOStatement;
use ReflectionClass;
use RuntimeException;

class DatabaseConnectionTest extends TestCase
{
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

    public function testSelectProperlyCallsPDO()
    {
        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['prepare'])->getMock();
        $writePdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['prepare'])->getMock();
        $writePdo->expects($this->never())->method('prepare');
        $statement = $this->getMockBuilder('PDOStatement')
            ->onlyMethods(['setFetchMode', 'execute', 'fetchAll', 'bindValue'])
            ->getMock();
        $statement->expects($this->once())->method('setFetchMode');
        $statement->expects($this->once())->method('bindValue')->with('foo', 'bar', 2);
        $statement->expects($this->once())->method('execute');
        $statement->expects($this->once())->method('fetchAll')->willReturn(['boom']);
        $pdo->expects($this->once())->method('prepare')->with('foo')->willReturn($statement);
        $mock = $this->getMockConnection(['prepareBindings'], $writePdo);
        $mock->setReadPdo($pdo);
        $mock->expects($this->once())->method('prepareBindings')->with($this->equalTo(['foo' => 'bar']))->willReturn(['foo' => 'bar']);
        $results = $mock->select('foo', ['foo' => 'bar']);
        $this->assertEquals(['boom'], $results);
        $log = $mock->getQueryLog();
        $this->assertSame('foo', $log[0]['query']);
        $this->assertEquals(['foo' => 'bar'], $log[0]['bindings']);
        $this->assertIsNumeric($log[0]['time']);
    }

    public function testSelectResultsetsReturnsMultipleRowset(): void
    {
        $configurator = new StatementPathSessionConfigurator;
        Connection::configureSessionUsing($configurator);
        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['prepare'])->getMock();
        $writePdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['prepare'])->getMock();
        $writePdo->expects($this->never())->method('prepare');
        $statement = $this->getMockBuilder('PDOStatement')
            ->onlyMethods(['setFetchMode', 'execute', 'fetchAll', 'bindValue', 'nextRowset'])
            ->getMock();
        $statement->expects($this->once())->method('setFetchMode');
        $statement->expects($this->once())->method('bindValue')->with(1, 'foo', 2);
        $statement->expects($this->once())->method('execute');
        $statement->expects($this->atLeastOnce())->method('fetchAll')->willReturn(['boom']);
        $statement->expects($this->atLeastOnce())->method('nextRowset')->willReturnCallback(function () {
            static $i = 1;

            return ++$i <= 2;
        });
        $pdo->expects($this->once())->method('prepare')->with('CALL a_procedure(?)')->willReturn($statement);
        $mock = $this->getMockConnection(['prepareBindings'], $writePdo);
        $mock->setReadPdo($pdo);
        $mock->expects($this->once())->method('prepareBindings')->with($this->equalTo(['foo']))->willReturn(['foo']);
        $results = $mock->selectResultsets('CALL a_procedure(?)', ['foo']);
        $this->assertEquals([['boom'], ['boom']], $results);
        $log = $mock->getQueryLog();
        $this->assertSame('CALL a_procedure(?)', $log[0]['query']);
        $this->assertEquals(['foo'], $log[0]['bindings']);
        $this->assertIsNumeric($log[0]['time']);
        $this->assertSame(1, $configurator->stateCalls);
        $this->assertSame(1, $configurator->applyCalls);
    }

    public function testEveryOrdinaryConnectionStatementClosureSynchronizesItsPdo(): void
    {
        $configurator = new StatementPathSessionConfigurator;
        Connection::configureSessionUsing($configurator);
        $connection = new Connection(
            new PDO('sqlite::memory:'),
            ':memory:',
            '',
            ['name' => 'test', 'driver' => 'sqlite']
        );

        $operations = [
            static fn () => $connection->select('select 1'),
            static fn () => iterator_to_array($connection->cursor('select 1')),
            static fn () => $connection->statement('create table records (id integer primary key)'),
            static fn () => $connection->affectingStatement('insert into records (id) values (1)'),
            static fn () => $connection->unprepared('delete from records'),
        ];

        foreach ($operations as $index => $operation) {
            $configurator->desiredState = 'state-' . $index;
            $operation();
            $this->assertSame($index + 1, $configurator->applyCalls);
        }

        $this->assertSame(count($operations), $configurator->stateCalls);
    }

    public function testPretendModeDoesNotResolveOrSynchronizePdo(): void
    {
        $configurator = new StatementPathSessionConfigurator;
        Connection::configureSessionUsing($configurator);
        $resolutions = 0;
        $connection = new Connection(
            static function () use (&$resolutions): PDO {
                ++$resolutions;

                return new PDO('sqlite::memory:');
            },
            ':memory:',
            '',
            ['name' => 'test', 'driver' => 'sqlite']
        );

        $connection->pretend(static function (Connection $connection): void {
            $connection->select('select 1');
            $connection->statement('create table records (id integer)');
            $connection->affectingStatement('delete from records');
            $connection->unprepared('delete from records');
        });

        $this->assertSame(0, $resolutions);
        $this->assertSame(0, $configurator->stateCalls);
        $this->assertSame(0, $configurator->applyCalls);
    }

    public function testMySqlInsertUsesOneSynchronizedPdoForExecutionAndInsertId(): void
    {
        $configurator = new StatementPathSessionConfigurator;
        Connection::configureSessionUsing($configurator);
        $pdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['prepare', 'lastInsertId'])
            ->getMock();
        $statement = $this->getMockBuilder(PDOStatement::class)
            ->onlyMethods(['execute'])
            ->getMock();
        $pdo->expects($this->once())->method('prepare')->with('insert into records values ()')->willReturn($statement);
        $pdo->expects($this->once())->method('lastInsertId')->with(null)->willReturn('42');
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $connection = new MySqlConnection(
            $pdo,
            'test_database',
            '',
            ['name' => 'test', 'driver' => 'mysql']
        );

        $this->assertTrue($connection->insert('insert into records values ()'));
        $this->assertSame('42', $connection->getLastInsertId());
        $this->assertSame(1, $configurator->stateCalls);
        $this->assertSame(1, $configurator->applyCalls);
    }

    public function testEscapingAndServerIntrospectionUseSynchronizedPdoHandOuts(): void
    {
        $configurator = new StatementPathSessionConfigurator;
        Connection::configureSessionUsing($configurator);
        $connection = new Connection(
            new PDO('sqlite::memory:'),
            ':memory:',
            '',
            ['name' => 'test', 'driver' => 'sqlite']
        );

        $this->assertSame("'value'", $connection->escape('value'));
        $this->assertNotSame('', $connection->getServerVersion());
        $this->assertSame(2, $configurator->stateCalls);
        $this->assertSame(1, $configurator->applyCalls);
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

    public function testStatementProperlyCallsPDO()
    {
        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['prepare'])->getMock();
        $statement = $this->getMockBuilder('PDOStatement')->onlyMethods(['execute', 'bindValue'])->getMock();
        $statement->expects($this->once())->method('bindValue')->with(1, 'bar', 2);
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $pdo->expects($this->once())->method('prepare')->with($this->equalTo('foo'))->willReturn($statement);
        $mock = $this->getMockConnection(['prepareBindings'], $pdo);
        $mock->expects($this->once())->method('prepareBindings')->with($this->equalTo(['bar']))->willReturn(['bar']);
        $results = $mock->statement('foo', ['bar']);
        $this->assertTrue($results);
        $log = $mock->getQueryLog();
        $this->assertSame('foo', $log[0]['query']);
        $this->assertEquals(['bar'], $log[0]['bindings']);
        $this->assertIsNumeric($log[0]['time']);
    }

    public function testAffectingStatementProperlyCallsPDO()
    {
        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['prepare'])->getMock();
        $statement = $this->getMockBuilder('PDOStatement')->onlyMethods(['execute', 'rowCount', 'bindValue'])->getMock();
        $statement->expects($this->once())->method('bindValue')->with('foo', 'bar', 2);
        $statement->expects($this->once())->method('execute');
        $statement->expects($this->once())->method('rowCount')->willReturn(42);
        $pdo->expects($this->once())->method('prepare')->with('foo')->willReturn($statement);
        $mock = $this->getMockConnection(['prepareBindings'], $pdo);
        $mock->expects($this->once())->method('prepareBindings')->with($this->equalTo(['foo' => 'bar']))->willReturn(['foo' => 'bar']);
        $results = $mock->update('foo', ['foo' => 'bar']);
        $this->assertSame(42, $results);
        $log = $mock->getQueryLog();
        $this->assertSame('foo', $log[0]['query']);
        $this->assertEquals(['foo' => 'bar'], $log[0]['bindings']);
        $this->assertIsNumeric($log[0]['time']);
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

    public function testSwapPDOWithOpenTransactionResetsTransactionLevel()
    {
        $pdo = $this->createMock(PDOStub::class);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $connection = $this->getMockConnection([], $pdo);
        $connection->beginTransaction();
        $connection->disconnect();
        $this->assertEquals(0, $connection->transactionLevel());
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

    public function testOnLostConnectionPDOIsNotSwappedWithinATransaction()
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('server has gone away (Connection: test, Host: , Port: , Database: , SQL: foo)');

        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('beginTransaction')->once();
        $statement = m::mock(PDOStatement::class);
        $pdo->shouldReceive('prepare')->once()->andReturn($statement);
        $statement->shouldReceive('execute')->once()->andThrow(new PDOException('server has gone away'));

        $connection = new Connection($pdo, '', '', ['name' => 'test', 'driver' => 'mysql']);
        $connection->beginTransaction();
        $connection->statement('foo');
    }

    public function testOnLostConnectionPDOIsSwappedOutsideTransaction()
    {
        $pdo = m::mock(PDO::class);

        $statement = m::mock(PDOStatement::class);
        $statement->shouldReceive('execute')->once()->andThrow(new PDOException('server has gone away'));
        $statement->shouldReceive('execute')->once()->andReturn(true);

        $pdo->shouldReceive('prepare')->twice()->andReturn($statement);

        $connection = new Connection($pdo, '', '', ['name' => 'test', 'driver' => 'mysql']);

        $called = false;

        $connection->setReconnector(function ($connection) use (&$called) {
            $called = true;
        });

        $this->assertTrue($connection->statement('foo'));

        $this->assertTrue($called);
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

    public function testExplicitPhysicalCommitFailureLeavesTheTransactionCallerOwned(): void
    {
        $failure = new RuntimeException('commit failure');
        $pdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['beginTransaction', 'commit', 'inTransaction', 'rollBack'])
            ->getMock();
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('commit')->willThrowException($failure);
        $pdo->expects($this->once())->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack');

        $connection = $this->getMockConnection([], $pdo);
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $connection->beginTransaction();

        try {
            $connection->commit();
            $this->fail('Expected the physical commit to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, $connection->transactionLevel());
        $this->assertCount(1, $manager->getPendingTransactions());
        $this->assertSame($pdo, $connection->getRawPdo());

        $connection->rollBack();
    }

    public function testLostManagedCommitTerminallyDetachesTransactionState(): void
    {
        $failure = new PDOException('server has gone away');
        $pdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['beginTransaction', 'commit', 'inTransaction', 'rollBack'])
            ->getMock();
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('commit')->willThrowException($failure);
        $pdo->expects($this->never())->method('inTransaction');
        $pdo->expects($this->never())->method('rollBack');

        $connection = $this->getMockConnection([], $pdo);
        $connection->setReadPdo(new PDOStub);
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $rollbackCallbackCalled = false;

        try {
            $connection->transaction(function (Connection $connection) use (&$rollbackCallbackCalled): void {
                $connection->afterRollBack(function () use (&$rollbackCallbackCalled): void {
                    $rollbackCallbackCalled = true;
                });
            });
            $this->fail('Expected the lost commit to fail.');
        } catch (PDOException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertTrue($rollbackCallbackCalled);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertCount(0, $manager->getPendingTransactions());
        $this->assertCount(0, $manager->getCommittedTransactions());
        $this->assertNull($connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
    }

    public function testNonLostPhysicalRollbackFailureKeepsActiveStateAndMarksTheSessionUnknown(): void
    {
        Connection::configureSessionUsing(new StatementPathSessionConfigurator);

        $failure = new RuntimeException('rollback failure');
        $pdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['beginTransaction', 'inTransaction', 'rollBack'])
            ->getMock();
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willThrowException($failure);

        $connection = $this->getMockConnection([], $pdo);
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $connection->beginTransaction();

        try {
            $connection->rollBack();
            $this->fail('Expected the physical rollback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, $connection->transactionLevel());
        $this->assertCount(1, $manager->getPendingTransactions());
        $this->assertTrue($connection->hasUnknownSessionState());
        $this->assertSame($pdo, $connection->getRawPdo());
    }

    public function testLostPhysicalRollbackTerminallyDetachesTransactionState(): void
    {
        $failure = new PDOException('server has gone away');
        $pdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['beginTransaction', 'inTransaction', 'rollBack'])
            ->getMock();
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willThrowException($failure);

        $connection = $this->getMockConnection([], $pdo);
        $connection->setReadPdo(new PDOStub);
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $connection->beginTransaction();
        $rollbackCallbackCalled = false;
        $connection->afterRollBack(function () use (&$rollbackCallbackCalled): void {
            $rollbackCallbackCalled = true;
        });

        try {
            $connection->rollBack();
            $this->fail('Expected the lost rollback to fail.');
        } catch (PDOException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertTrue($rollbackCallbackCalled);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertCount(0, $manager->getPendingTransactions());
        $this->assertNull($connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
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

    public function testDisconnectExhaustsCleanupAndPreservesThePhysicalFailure(): void
    {
        $physicalFailure = new RuntimeException('physical rollback failure');
        $callbackFailure = new RuntimeException('rollback callback failure');
        $pdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['beginTransaction', 'inTransaction', 'rollBack'])
            ->getMock();
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willThrowException($physicalFailure);

        $connection = $this->getMockConnection([], $pdo);
        $connection->setReadPdo(new PDOStub);
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $connection->beginTransaction();
        $rollbackCallbackCalled = false;
        $connection->afterRollBack(function () use (&$rollbackCallbackCalled, $callbackFailure): never {
            $rollbackCallbackCalled = true;

            throw $callbackFailure;
        });

        try {
            $connection->disconnect();
            $this->fail('Expected disconnect cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($physicalFailure, $exception);
        }

        $this->assertTrue($rollbackCallbackCalled);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertCount(0, $manager->getPendingTransactions());
        $this->assertNull($connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
    }

    public function testDisconnectTreatsLostPhysicalRollbackFailureAsAlreadyTerminal(): void
    {
        $pdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['inTransaction', 'rollBack'])
            ->getMock();
        $pdo->expects($this->once())->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willThrowException(
            new PDOException('SQLSTATE[HY000]: General error: 7 no connection to the server')
        );

        $connection = $this->getMockConnection([], $pdo);
        $connection->setReadPdo(new PDOStub);
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $manager->begin('test', 1);

        $connection->disconnect();

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertCount(0, $manager->getPendingTransactions());
        $this->assertNull($connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
    }

    public function testDisconnectPreservesManagerFailureAfterLostPhysicalRollbackFailure(): void
    {
        $callbackFailure = new RuntimeException('rollback callback failure');
        $pdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['inTransaction', 'rollBack'])
            ->getMock();
        $pdo->expects($this->once())->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willThrowException(
            new PDOException('SQLSTATE[HY000]: General error: 7 no connection to the server')
        );

        $connection = $this->getMockConnection([], $pdo);
        $connection->setReadPdo(new PDOStub);
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $manager->begin('test', 1);
        $manager->addCallbackForRollback(static function () use ($callbackFailure): never {
            throw $callbackFailure;
        });

        try {
            $connection->disconnect();
            $this->fail('Expected disconnect manager cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($callbackFailure, $exception);
        }

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertCount(0, $manager->getPendingTransactions());
        $this->assertNull($connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
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
        $connection = new Connection($writePdo, 'write_db', '', $writeConfig);
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

        $connection = new Connection($writePdo, 'write_db', '', $writeConfig);
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

        $connection = new Connection($pdo, 'read_db', '', [
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

        $connection = new Connection($writePdo, 'write_db', '', $writeConfig);
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
        $connection = new Connection(function () {
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

    protected function getSqliteTransactionConnection(): Connection
    {
        return new Connection(
            new PDO('sqlite::memory:'),
            ':memory:',
            '',
            ['name' => 'default', 'driver' => 'sqlite']
        );
    }

    /**
     * Create a read / write connection for sticky routing assertions.
     *
     * @return array{0: Connection, 1: PDOStub, 2: PDOStub}
     */
    protected function getReadWriteConnection(bool $sticky): array
    {
        $writePdo = new PDOStub;
        $readPdo = new PDOStub;

        $connection = new Connection($writePdo, 'test_db', '', [
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
            $connection = new Connection($pdo, 'test_db', '', ['name' => 'test', 'driver' => 'mysql']);
            $connection->setSchemaGrammar(m::mock(SchemaGrammar::class));
            $connection->enableQueryLog();

            return $connection;
        }

        $defaults = ['getDefaultQueryGrammar', 'getDefaultPostProcessor', 'getDefaultSchemaGrammar'];
        $connection = $this->getMockBuilder(Connection::class)->onlyMethods(array_values(array_unique(array_merge($defaults, $methods))))->setConstructorArgs([$pdo, 'test_db', '', ['name' => 'test', 'driver' => 'mysql']])->getMock();
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

class StatementPathSessionConfigurator implements SessionConfigurator
{
    public string $desiredState = 'state';

    public int $stateCalls = 0;

    public int $applyCalls = 0;

    public function state(Connection $connection): ?string
    {
        ++$this->stateCalls;

        return $this->desiredState;
    }

    public function apply(PDO $pdo, string $state, Connection $connection): void
    {
        ++$this->applyCalls;
    }
}
