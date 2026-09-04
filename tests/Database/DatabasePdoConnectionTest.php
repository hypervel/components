<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabasePdoConnectionTest;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\BinaryParameter;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\StatementPrepared;
use Hypervel\Database\MariaDbConnection;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Grammars\Grammar as SchemaGrammar;
use Hypervel\Database\SessionConfigurator;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

class DatabasePdoConnectionTest extends TestCase
{
    public function testSelectProperlyCallsPDO(): void
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
        PdoConnection::configureSessionUsing($configurator);
        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['prepare'])->getMock();
        $writePdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['prepare'])->getMock();
        $writePdo->expects($this->never())->method('prepare');
        $statement = $this->getMockBuilder('PDOStatement')
            ->onlyMethods(['setFetchMode', 'execute', 'fetchAll', 'bindValue', 'nextRowset'])
            ->getMock();
        $statement->expects($this->once())->method('setFetchMode');
        $statement->expects($this->once())->method('bindValue')->with(1, 'foo', 2);
        $statement->expects($this->once())->method('execute');
        $statement->expects($this->atLeastOnce())->method('fetchAll')->with(PDO::FETCH_COLUMN, 1)->willReturn(['boom']);
        $statement->expects($this->atLeastOnce())->method('nextRowset')->willReturnCallback(function () {
            static $i = 1;

            return ++$i <= 2;
        });
        $pdo->expects($this->once())->method('prepare')->with('CALL a_procedure(?)')->willReturn($statement);
        $mock = $this->getMockConnection(['prepareBindings'], $writePdo);
        $mock->setReadPdo($pdo);
        $mock->expects($this->once())->method('prepareBindings')->with($this->equalTo(['foo']))->willReturn(['foo']);
        $results = $mock->selectResultsets('CALL a_procedure(?)', ['foo'], true, [PDO::FETCH_COLUMN, 1]);
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
        PdoConnection::configureSessionUsing($configurator);
        $connection = new PdoConnection(
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
        PdoConnection::configureSessionUsing($configurator);
        $resolutions = 0;
        $connection = new PdoConnection(
            static function () use (&$resolutions): PDO {
                ++$resolutions;

                return new PDO('sqlite::memory:');
            },
            ':memory:',
            '',
            ['name' => 'test', 'driver' => 'sqlite']
        );

        $cursorRows = null;
        $queries = $connection->pretend(static function (Connection $connection) use (&$cursorRows): void {
            $connection->select('select 1');
            $cursorRows = iterator_to_array($connection->cursor('select cursor_value'));
            $connection->statement('create table records (id integer)');
            $connection->affectingStatement('delete from records');
            $connection->unprepared('delete from records');
        });

        $this->assertSame([], $cursorRows);
        $this->assertSame('select cursor_value', $queries[1]['query']);
        $this->assertSame(0, $resolutions);
        $this->assertSame(0, $configurator->stateCalls);
        $this->assertSame(0, $configurator->applyCalls);
    }

    public function testCursorPreservesFalseyValuesWithCustomFetchMode(): void
    {
        $connection = $this->getSqliteTransactionConnection();
        $connection->statement('create table records (id integer primary key, value text null)');
        $connection->insert("insert into records (id, value) values (1, null), (2, ''), (3, '0'), (4, 'later')");

        $this->assertSame(
            [null, '', '0', 'later'],
            iterator_to_array($connection->cursor(
                'select id, value from records order by id',
                fetchUsing: [PDO::FETCH_COLUMN, 1]
            ))
        );
    }

    public function testCursorPreservesModeOnlyFetchDefaults(): void
    {
        $connection = $this->getSqliteTransactionConnection();

        $this->assertSame(
            ['first', 'second'],
            iterator_to_array($connection->cursor(
                "select 'first' as value union all select 'second'",
                fetchUsing: [PDO::FETCH_COLUMN]
            ))
        );

        $classRows = iterator_to_array($connection->cursor(
            "select 'class' as value",
            fetchUsing: [PDO::FETCH_CLASS]
        ));
        $this->assertInstanceOf(stdClass::class, $classRows[0]);
        $this->assertSame('class', $classRows[0]->value);

        $this->assertSame(
            [1, 2],
            iterator_to_array($connection->cursor(
                'select 1 as value union all select 2',
                fetchUsing: [PDO::FETCH_GROUP | PDO::FETCH_COLUMN]
            ))
        );

        $classTypeRows = iterator_to_array($connection->cursor(
            "select 'stdClass' as class_name, 'typed' as value",
            fetchUsing: [PDO::FETCH_CLASS | PDO::FETCH_CLASSTYPE]
        ));
        $this->assertInstanceOf(stdClass::class, $classTypeRows[0]);
        $this->assertSame('typed', $classTypeRows[0]->value);
    }

    public function testMySqlInsertUsesOneSynchronizedPdoForExecutionAndInsertId(): void
    {
        $configurator = new StatementPathSessionConfigurator;
        PdoConnection::configureSessionUsing($configurator);
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

    public function testPdoLastInsertIdFailureThrowsARuntimeException(): void
    {
        $pdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['lastInsertId'])
            ->getMock();
        $pdo->expects($this->once())
            ->method('lastInsertId')
            ->with('records_id_seq')
            ->willReturn(false);
        $connection = $this->getMockConnection([], $pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The database driver could not retrieve the last insert ID.');

        $connection->getLastInsertId('records_id_seq');
    }

    public function testMySqlInsertWrapsLastInsertIdFailureAsAQueryException(): void
    {
        $pdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['prepare', 'lastInsertId'])
            ->getMock();
        $statement = $this->getMockBuilder(PDOStatement::class)
            ->onlyMethods(['execute'])
            ->getMock();
        $pdo->expects($this->once())->method('prepare')->with('insert into records values ()')->willReturn($statement);
        $pdo->expects($this->once())->method('lastInsertId')->with(null)->willReturn(false);
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $connection = new MySqlConnection(
            $pdo,
            'test_database',
            '',
            ['name' => 'test', 'driver' => 'mysql']
        );

        try {
            $connection->insert('insert into records values ()');
            $this->fail('Expected last insert ID retrieval to fail.');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            $this->assertSame(
                'The database driver could not retrieve the last insert ID.',
                $exception->getPrevious()->getMessage(),
            );
        }
    }

    public function testMySqlLastInsertIdRequiresACapturedInsert(): void
    {
        $connection = new MySqlConnection(
            new PDOStub,
            'test_database',
            '',
            ['name' => 'test', 'driver' => 'mysql']
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No last insert ID has been captured for this connection.');

        $connection->getLastInsertId();
    }

    public function testMySqlCapturedInsertIdIsClearedWheneverItsGenerationOrPoolStateChanges(): void
    {
        $resets = [
            'set PDO' => static function (MySqlConnection $connection): void {
                $connection->setPdo(new PDO('sqlite::memory:'));
            },
            'disconnect' => static function (MySqlConnection $connection): void {
                $connection->disconnect();
            },
            'refresh' => static function (MySqlConnection $connection): void {
                $connection->refreshFrom(new MySqlConnection(
                    new PDO('sqlite::memory:'),
                    'test_database',
                    '',
                    ['name' => 'test', 'driver' => 'mysql']
                ));
            },
            'pool reset' => static function (MySqlConnection $connection): void {
                $connection->resetForPool();
            },
        ];

        foreach ($resets as $reset => $operation) {
            $pdo = new PDO('sqlite::memory:');
            $pdo->exec('create table records (id integer primary key autoincrement)');
            $connection = new MySqlConnection(
                $pdo,
                'test_database',
                '',
                ['name' => 'test', 'driver' => 'mysql']
            );
            $connection->insert('insert into records default values');
            $this->assertSame('1', $connection->getLastInsertId(), $reset);

            $operation($connection);

            $exception = null;

            try {
                $connection->getLastInsertId();
            } catch (RuntimeException $thrown) {
                $exception = $thrown;
            }

            $this->assertInstanceOf(RuntimeException::class, $exception, $reset);
            $this->assertSame(
                'No last insert ID has been captured for this connection.',
                $exception->getMessage(),
                $reset,
            );
        }
    }

    public function testStatementPreparedIsDispatchedFromThePdoPreparationPath(): void
    {
        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['prepare'])->getMock();
        $statement = $this->getMockBuilder(PDOStatement::class)
            ->onlyMethods(['setFetchMode', 'execute', 'fetchAll'])
            ->getMock();
        $pdo->expects($this->once())->method('prepare')->with('select 1')->willReturn($statement);
        $statement->expects($this->once())->method('setFetchMode')->with(PDO::FETCH_OBJ);
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $statement->expects($this->once())->method('fetchAll')->willReturn([]);
        $connection = $this->getMockConnection([], $pdo);
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(StatementPrepared::class)->andReturn(true);
        $events->shouldReceive('hasListeners')->once()->with(QueryExecuted::class)->andReturn(false);
        $events->shouldReceive('dispatch')
            ->once()
            ->with(m::on(static fn (StatementPrepared $event): bool => $event->connection === $connection
                && $event->statement === $statement));
        $connection->setEventDispatcher($events);

        $this->assertSame([], $connection->select('select 1', useReadPdo: false));
    }

    public function testStatementPreparedDoesNotEnterEventSeamWithoutListeners(): void
    {
        $pdo = $this->getMockBuilder(PDOStub::class)->onlyMethods(['prepare'])->getMock();
        $statement = $this->getMockBuilder(PDOStatement::class)
            ->onlyMethods(['setFetchMode', 'execute', 'fetchAll'])
            ->getMock();
        $pdo->expects($this->once())->method('prepare')->with('select 1')->willReturn($statement);
        $statement->expects($this->once())->method('setFetchMode')->with(PDO::FETCH_OBJ);
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $statement->expects($this->once())->method('fetchAll')->willReturn([]);

        $connection = new class($pdo, 'test_db', '', ['name' => 'test', 'driver' => 'mysql']) extends PdoConnection {
            public int $eventCalls = 0;

            protected function event(object $event): void
            {
                ++$this->eventCalls;

                parent::event($event);
            }
        };
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(StatementPrepared::class)->andReturnFalse();
        $events->shouldReceive('hasListeners')->once()->with(QueryExecuted::class)->andReturnFalse();
        $events->shouldNotReceive('dispatch');
        $connection->setEventDispatcher($events);

        $this->assertSame([], $connection->select('select 1', useReadPdo: false));
        $this->assertSame(0, $connection->eventCalls);
    }

    public function testEscapingAndServerIntrospectionUseSynchronizedPdoHandOuts(): void
    {
        $configurator = new StatementPathSessionConfigurator;
        PdoConnection::configureSessionUsing($configurator);
        $connection = new PdoConnection(
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

    #[DataProvider('databaseCapabilityProvider')]
    public function testDatabaseCapabilities(
        string $connectionClass,
        string $serverVersion,
        ?string $configuredVersion,
        bool|string $expectedLock,
        int $expectedMaxBindings,
    ): void {
        $pdo = new VersionedPDOStub($serverVersion);
        $config = ['name' => 'test'];

        if ($configuredVersion !== null) {
            $config['version'] = $configuredVersion;
        }

        /** @var class-string<PdoConnection> $connectionClass */
        $connection = new $connectionClass($pdo, 'test_db', '', $config);

        $this->assertSame($expectedLock, $connection->lockForPopping());
        $this->assertSame($expectedMaxBindings, $connection->maxBindings());
    }

    public static function databaseCapabilityProvider(): array
    {
        return [
            'unknown PDO connection' => [PdoConnection::class, '1.0', null, true, 999],
            'MySQL before SKIP LOCKED' => [MySqlConnection::class, '8.0.0', null, true, 65_535],
            'MySQL with SKIP LOCKED' => [MySqlConnection::class, '8.0.1', null, 'FOR UPDATE SKIP LOCKED', 65_535],
            'MariaDB through MySQL before SKIP LOCKED' => [MySqlConnection::class, '5.5.5-10.5.99-MariaDB', null, true, 65_535],
            'MariaDB through MySQL with SKIP LOCKED' => [MySqlConnection::class, '5.5.5-10.6.0-MariaDB', null, 'FOR UPDATE SKIP LOCKED', 65_535],
            'MariaDB connection' => [MariaDbConnection::class, '5.5.5-10.6.0-MariaDB', null, 'FOR UPDATE SKIP LOCKED', 65_535],
            'configured MySQL override' => [MySqlConnection::class, '8.0.0', '8.0.1', 'FOR UPDATE SKIP LOCKED', 65_535],
            'configured MariaDB marker' => [MySqlConnection::class, '8.0.0', '5.5.5-10.6.0-MariaDB', 'FOR UPDATE SKIP LOCKED', 65_535],
            'Vitess before SKIP LOCKED' => [MySqlConnection::class, '8.0.0', '18.0.0-vitess', true, 65_535],
            'Vitess with SKIP LOCKED' => [MySqlConnection::class, '8.0.0', '19.0.0-vitess', 'FOR UPDATE SKIP LOCKED', 65_535],
            'PlanetScale with SKIP LOCKED' => [MySqlConnection::class, '8.0.0', '19.0.0-PlanetScale', 'FOR UPDATE SKIP LOCKED', 65_535],
            'PostgreSQL before SKIP LOCKED' => [PostgresConnection::class, '9.4', null, true, 65_535],
            'PostgreSQL with SKIP LOCKED' => [PostgresConnection::class, '9.5', null, 'FOR UPDATE SKIP LOCKED', 65_535],
            'SQLite before expanded binding limit' => [SQLiteConnection::class, '3.31.1', null, true, 999],
            'SQLite with expanded binding limit' => [SQLiteConnection::class, '3.32.0', null, true, 32_766],
        ];
    }

    public function testDatabaseCapabilitiesAreCachedUntilThePdoIsReplaced(): void
    {
        $connection = new class(new PDOStub, 'test_db') extends PdoConnection {
            public int $lockResolutionCount = 0;

            public int $bindingResolutionCount = 0;

            protected function resolveLockForPopping(): bool|string
            {
                return ++$this->lockResolutionCount === 1 ? true : 'FOR UPDATE SKIP LOCKED';
            }

            protected function resolveMaxBindings(): int
            {
                return ++$this->bindingResolutionCount === 1 ? 999 : 65_535;
            }
        };

        $this->assertTrue($connection->lockForPopping());
        $this->assertTrue($connection->lockForPopping());
        $this->assertSame(999, $connection->maxBindings());
        $this->assertSame(999, $connection->maxBindings());
        $this->assertSame(1, $connection->lockResolutionCount);
        $this->assertSame(1, $connection->bindingResolutionCount);

        $connection->setPdo(new PDOStub);

        $this->assertSame('FOR UPDATE SKIP LOCKED', $connection->lockForPopping());
        $this->assertSame(65_535, $connection->maxBindings());
        $this->assertSame(2, $connection->lockResolutionCount);
        $this->assertSame(2, $connection->bindingResolutionCount);
    }

    public function testEscapingUsesThePdoThatExecutedTheLastWrite(): void
    {
        $writePdo = new EscapingPdoStub('write');
        $readResolutions = 0;
        $connection = new PdoConnection(
            $writePdo,
            ':memory:',
            '',
            ['name' => 'test', 'driver' => 'sqlite']
        );
        $connection->setReadPdo(static function () use (&$readResolutions): PDO {
            ++$readResolutions;

            return new EscapingPdoStub('read');
        });

        $connection->unprepared('create table records (id integer)');

        $this->assertSame("'write:value'", $connection->escape('value'));
        $this->assertSame(0, $readResolutions);
    }

    public function testEscapingUsesThePdoThatExecutedTheLastRead(): void
    {
        $writeResolutions = 0;
        $connection = new PdoConnection(
            static function () use (&$writeResolutions): PDO {
                ++$writeResolutions;

                return new EscapingPdoStub('write');
            },
            ':memory:',
            '',
            ['name' => 'test', 'driver' => 'sqlite']
        );
        $connection->setReadPdo(new EscapingPdoStub('read'));

        $connection->select('select 1');

        $this->assertSame("'read:value'", $connection->escape('value'));
        $this->assertSame(0, $writeResolutions);
    }

    public function testEscapingUsesTheWritePdoAfterAWriteForcedRead(): void
    {
        $writePdo = new EscapingPdoStub('write');
        $readResolutions = 0;
        $connection = new PdoConnection(
            $writePdo,
            ':memory:',
            '',
            ['name' => 'test', 'driver' => 'sqlite']
        );
        $connection->setReadPdo(static function () use (&$readResolutions): PDO {
            ++$readResolutions;

            return new EscapingPdoStub('read');
        });

        $connection->selectFromWriteConnection('select 1');

        $this->assertSame("'write:value'", $connection->escape('value'));
        $this->assertSame(0, $readResolutions);
    }

    public function testEscapingUsesTheWritePdoAfterAStickyRead(): void
    {
        $writePdo = new EscapingPdoStub('write');
        $readResolutions = 0;
        $connection = new PdoConnection(
            $writePdo,
            ':memory:',
            '',
            ['name' => 'test', 'driver' => 'sqlite', 'sticky' => true]
        );
        $connection->setReadPdo(static function () use (&$readResolutions): PDO {
            ++$readResolutions;

            return new EscapingPdoStub('read');
        });
        $connection->recordsHaveBeenModified();

        $connection->select('select 1');

        $this->assertSame("'write:value'", $connection->escape('value'));
        $this->assertSame(0, $readResolutions);
    }

    public function testEscapingUsesTheConfiguredWriteRoleWithoutResolvingTheReadPdo(): void
    {
        $readResolutions = 0;
        $connection = new PdoConnection(
            new EscapingPdoStub('write'),
            ':memory:',
            '',
            [
                'name' => 'test',
                'driver' => 'sqlite',
                Connection::READ_WRITE_TYPE_CONFIG_KEY => 'write',
            ]
        );
        $connection->setReadPdo(static function () use (&$readResolutions): PDO {
            ++$readResolutions;

            return new EscapingPdoStub('read');
        });

        $this->assertSame("'write:value'", $connection->escape('value'));
        $this->assertSame(0, $readResolutions);
    }

    public function testEscapingDefaultsToTheReadPdoWithoutAPriorRole(): void
    {
        $writeResolutions = 0;
        $readResolutions = 0;
        $connection = new PdoConnection(
            static function () use (&$writeResolutions): PDO {
                ++$writeResolutions;

                return new EscapingPdoStub('write');
            },
            ':memory:',
            '',
            ['name' => 'test', 'driver' => 'sqlite']
        );
        $connection->setReadPdo(static function () use (&$readResolutions): PDO {
            ++$readResolutions;

            return new EscapingPdoStub('read');
        });

        $this->assertSame("'read:value'", $connection->escape('value'));
        $this->assertSame(0, $writeResolutions);
        $this->assertSame(1, $readResolutions);
    }

    public function testPoolResetClearsTheLastExecutionRoleBeforeEscaping(): void
    {
        $connection = new PdoConnection(
            new EscapingPdoStub('write'),
            ':memory:',
            '',
            ['name' => 'test', 'driver' => 'sqlite']
        );
        $connection->setReadPdo(new EscapingPdoStub('read'));
        $connection->unprepared('create table records (id integer)');

        $this->assertSame("'write:value'", $connection->escape('value'));

        $connection->resetForPool();

        $this->assertSame("'read:value'", $connection->escape('value'));
    }

    public function testBindValuesUsesExplicitBinaryTypeWithoutChangingExistingBindingTypes(): void
    {
        $resource = fopen('php://memory', 'r+');
        $statement = $this->getMockBuilder(PDOStatement::class)
            ->onlyMethods(['bindValue'])
            ->getMock();
        $boundValues = [];

        $statement->expects($this->exactly(4))
            ->method('bindValue')
            ->willReturnCallback(function (string|int $parameter, mixed $value, int $type) use (&$boundValues): bool {
                $boundValues[] = [$parameter, $value, $type];

                return true;
            });

        try {
            (new PdoConnection(new PDOStub))->bindValues($statement, [
                'binary' => new BinaryParameter("\x00\xFF"),
                'string' => 'value',
                'integer' => 42,
                'resource' => $resource,
            ]);

            $this->assertSame([
                ['binary', "\x00\xFF", PDO::PARAM_LOB],
                ['string', 'value', PDO::PARAM_STR],
                ['integer', 42, PDO::PARAM_INT],
                ['resource', $resource, PDO::PARAM_LOB],
            ], $boundValues);
        } finally {
            fclose($resource);
        }
    }

    public function testStatementProperlyCallsPDO(): void
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

    public function testAffectingStatementProperlyCallsPDO(): void
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

    public function testSwapPDOWithOpenTransactionResetsTransactionLevel(): void
    {
        $pdo = $this->createMock(PDOStub::class);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $connection = $this->getMockConnection([], $pdo);
        $connection->beginTransaction();
        $connection->disconnect();
        $this->assertEquals(0, $connection->transactionLevel());
    }

    public function testOnLostConnectionPDOIsNotSwappedWithinATransaction(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('server has gone away (Connection: test, Host: , Port: , Database: , SQL: foo)');

        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('beginTransaction')->once();
        $statement = m::mock(PDOStatement::class);
        $pdo->shouldReceive('prepare')->once()->andReturn($statement);
        $statement->shouldReceive('execute')->once()->andThrow(new PDOException('server has gone away'));

        $connection = new PdoConnection($pdo, '', '', ['name' => 'test', 'driver' => 'mysql']);
        $connection->beginTransaction();
        $connection->statement('foo');
    }

    public function testOnLostConnectionPDOIsSwappedOutsideTransaction(): void
    {
        $pdo = m::mock(PDO::class);

        $statement = m::mock(PDOStatement::class);
        $statement->shouldReceive('execute')->once()->andThrow(new PDOException('server has gone away'));
        $statement->shouldReceive('execute')->once()->andReturn(true);

        $pdo->shouldReceive('prepare')->twice()->andReturn($statement);

        $connection = new PdoConnection($pdo, '', '', ['name' => 'test', 'driver' => 'mysql']);

        $called = false;

        $connection->setReconnector(function ($connection) use (&$called) {
            $called = true;
        });

        $this->assertTrue($connection->statement('foo'));

        $this->assertTrue($called);
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
        $this->assertFalse($connection->isReusable());

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
        $this->assertFalse($connection->isReusable());
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

        $connection->setPdo($pdo);

        $this->assertFalse($connection->isReusable());
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

    public function testRefreshRetainsConfiguredBaselinesWhenPreparationFails(): void
    {
        $oldPdo = new PDOStub;
        $connection = new PdoConnection(
            $oldPdo,
            'old_database',
            'old_',
            ['name' => 'test', 'driver' => 'sqlite', 'endpoint' => 'old']
        );
        $connection->setDatabaseName('tenant_database');
        $connection->setTablePrefix('tenant_');
        $failure = new RuntimeException('read connection failed');
        $fresh = new PdoConnection(
            new PDOStub,
            'fresh_database',
            'fresh_',
            ['name' => 'test', 'driver' => 'sqlite', 'endpoint' => 'fresh']
        );
        $fresh->setReadPdo(static fn (): never => throw $failure);

        try {
            $connection->refreshFrom($fresh);
            $this->fail('Expected replacement preparation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($oldPdo, $connection->getRawPdo());
        $this->assertSame('old', $connection->getConfig('endpoint'));

        $connection->resetForPool();

        $this->assertSame('old_database', $connection->getDatabaseName());
        $this->assertSame('old_', $connection->getTablePrefix());
    }

    /**
     * Adopt the prepared generation when transaction-manager cleanup fails.
     */
    public function testRefreshAdoptsPreparedGenerationWhenManagerCleanupFails(): void
    {
        $oldPdo = new PDO('sqlite::memory:');
        $freshWritePdo = new PDOStub;
        $freshReadPdo = new PDOStub;
        $connection = new PdoConnection(
            $oldPdo,
            'old_database',
            'old_',
            ['name' => 'test', 'driver' => 'sqlite', 'endpoint' => 'old']
        );
        $manager = new DatabaseTransactionsManager;
        $connection->setTransactionManager($manager);
        $connection->beginTransaction();
        $cleanupFailure = new RuntimeException('rollback callback failure');
        $connection->afterRollBack(static fn () => throw $cleanupFailure);
        $fresh = new PdoConnection(
            $freshWritePdo,
            'fresh_database',
            'fresh_',
            ['name' => 'test', 'driver' => 'sqlite', 'endpoint' => 'fresh']
        );
        $fresh->setReadPdo($freshReadPdo);

        try {
            $connection->refreshFrom($fresh);
            $this->fail('Expected transaction-manager cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($cleanupFailure, $exception);
        }

        $this->assertFalse($oldPdo->inTransaction());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertCount(0, $manager->getPendingTransactions());
        $this->assertCount(0, $manager->getCommittedTransactions());
        $this->assertSame($freshWritePdo, $connection->getRawPdo());
        $this->assertSame($freshReadPdo, $connection->getRawReadPdo());
        $this->assertSame('fresh_database', $connection->getDatabaseName());
        $this->assertSame('fresh_', $connection->getTablePrefix());
        $this->assertSame('fresh', $connection->getConfig('endpoint'));
        $this->assertTrue($connection->isReusable());

        $connection->setDatabaseName('tenant_database');
        $connection->setTablePrefix('tenant_');
        $connection->resetForPool();

        $this->assertSame('fresh_database', $connection->getDatabaseName());
        $this->assertSame('fresh_', $connection->getTablePrefix());
    }

    /**
     * Keep a failed old rollback from poisoning the prepared generation.
     */
    public function testRefreshAdoptsCleanPreparedGenerationWhenPhysicalCleanupFails(): void
    {
        $cleanupFailure = new RuntimeException('physical rollback failure');
        $oldPdo = $this->getMockBuilder(PDOStub::class)
            ->onlyMethods(['inTransaction', 'rollBack'])
            ->getMock();
        $oldPdo->expects($this->once())->method('inTransaction')->willReturn(true);
        $oldPdo->expects($this->once())->method('rollBack')->willThrowException($cleanupFailure);
        $freshWritePdo = new PDOStub;
        $freshReadPdo = new PDOStub;
        $connection = new PdoConnection(
            $oldPdo,
            'old_database',
            'old_',
            ['name' => 'test', 'driver' => 'mysql', 'endpoint' => 'old']
        );
        $fresh = new PdoConnection(
            $freshWritePdo,
            'fresh_database',
            'fresh_',
            ['name' => 'test', 'driver' => 'mysql', 'endpoint' => 'fresh']
        );
        $fresh->setReadPdo($freshReadPdo);

        try {
            $connection->refreshFrom($fresh);
            $this->fail('Expected physical cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($cleanupFailure, $exception);
        }

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame($freshWritePdo, $connection->getRawPdo());
        $this->assertSame($freshReadPdo, $connection->getRawReadPdo());
        $this->assertSame('fresh_database', $connection->getDatabaseName());
        $this->assertSame('fresh_', $connection->getTablePrefix());
        $this->assertSame('fresh', $connection->getConfig('endpoint'));
        $this->assertFalse((new PdoConnection($oldPdo, 'test_database'))->isReusable());
        $this->assertTrue($connection->isReusable());

        $connection->setDatabaseName('tenant_database');
        $connection->setTablePrefix('tenant_');
        $connection->resetForPool();

        $this->assertSame('fresh_database', $connection->getDatabaseName());
        $this->assertSame('fresh_', $connection->getTablePrefix());
    }

    public function testResetForPoolMarksALeakedForeignKeySuppressionScopeUnknown(): void
    {
        $connection = $this->getMockConnection();

        $connection->beginForeignKeyConstraintSuppression();
        $connection->resetForPool();

        $this->assertFalse($connection->isReusable());
        $this->assertTrue($connection->beginForeignKeyConstraintSuppression());

        $connection->endForeignKeyConstraintSuppression();
    }

    public function testResetForPoolDoesNotResolveALazyConnectionForALeakedForeignKeySuppressionScope(): void
    {
        $resolutions = 0;
        $connection = new PdoConnection(
            static function () use (&$resolutions): PDO {
                ++$resolutions;

                return new PDOStub;
            },
            'test_db',
            '',
            ['name' => 'test', 'driver' => 'mysql']
        );

        $connection->beginForeignKeyConstraintSuppression();
        $connection->resetForPool();

        $this->assertSame(0, $resolutions);
        $this->assertTrue($connection->isReusable());
        $this->assertTrue($connection->beginForeignKeyConstraintSuppression());

        $connection->endForeignKeyConstraintSuppression();
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

class PDOStub extends PDO
{
    public function __construct()
    {
    }
}

class VersionedPDOStub extends PDOStub
{
    public function __construct(private readonly string $serverVersion)
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->serverVersion;
    }
}

class EscapingPdoStub extends PDO
{
    public function __construct(protected string $role)
    {
        parent::__construct('sqlite::memory:');
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'{$this->role}:{$string}'";
    }
}

class StatementPathSessionConfigurator implements SessionConfigurator
{
    public string $desiredState = 'state';

    public int $stateCalls = 0;

    public int $applyCalls = 0;

    public function state(PdoConnection $connection): ?string
    {
        ++$this->stateCalls;

        return $this->desiredState;
    }

    public function apply(PDO $pdo, string $state, PdoConnection $connection): void
    {
        ++$this->applyCalls;
    }
}
