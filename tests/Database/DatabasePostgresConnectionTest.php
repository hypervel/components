<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use DateTimeImmutable;
use Hypervel\Database\PostgresConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class DatabasePostgresConnectionTest extends TestCase
{
    public function testBooleanBindingsAreStringifiedWhenUsingEmulatedPrepares(): void
    {
        $connection = $this->newConnection(emulatePrepares: true);

        $bindings = $connection->prepareBindings([
            'published' => true,
            'archived' => false,
            'created_at' => new DateTimeImmutable('2026-03-21 04:00:00'),
        ]);

        $this->assertSame([
            'published' => 'true',
            'archived' => 'false',
            'created_at' => '2026-03-21 04:00:00',
        ], $bindings);
    }

    public function testBooleanBindingsAreStringifiedWhenUsingTruthyEmulatedPreparesOption(): void
    {
        $connection = $this->newConnection(emulatePrepares: 1);

        $this->assertSame(['true', 'false'], $connection->prepareBindings([true, false]));
    }

    public function testBooleanBindingsUseDefaultIntegerConversionWhenNotUsingEmulatedPrepares(): void
    {
        $connection = $this->newConnection(emulatePrepares: false);

        $bindings = $connection->prepareBindings([
            'published' => true,
            'archived' => false,
            'created_at' => new DateTimeImmutable('2026-03-21 04:00:00'),
        ]);

        $this->assertSame([
            'published' => 1,
            'archived' => 0,
            'created_at' => '2026-03-21 04:00:00',
        ], $bindings);
    }

    public function testBooleanBindingsUseReadPdoConfigWhenReadConnectionIsActive(): void
    {
        $connection = $this->newConnection(emulatePrepares: false);
        $connection->setReadPdoConfig([
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => true,
            ],
        ]);
        $connection->setReadWriteType('read');

        $this->assertSame(['true', 'false'], $connection->prepareBindings([true, false]));
    }

    // REMOVED: Direct PDO configuration; use a separate named connection for that endpoint.

    public function testPrepareBindingsUsesWriteConnectionConfiguration(): void
    {
        $connection = $this->newConnection(emulatePrepares: true);
        $connection->setReadPdoConfig([
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ]);
        $connection->setReadWriteType('write');

        $this->assertSame(['true', 'false'], $connection->prepareBindings([true, false]));
    }

    public function testEscapeUsesPostgresBooleanLiterals(): void
    {
        $connection = $this->newConnection(emulatePrepares: true);

        $this->assertSame('true', $connection->escape(true));
        $this->assertSame('false', $connection->escape(false));
    }

    #[DataProvider('commitIdWaitStatuses')]
    public function testWaitingForACommitIdUsesOnlyTheReadConnection(string $status, ?bool $immediateResult, bool $expected): void
    {
        $readPdo = $this->commitIdReadPdo('19.0');
        $readPdo->expects('prepare')
            ->with("wait for lsn '0/16B3748' with (timeout '5s', no_throw)")
            ->andReturn($this->commitIdStatement($status));

        if ($immediateResult !== null) {
            $readPdo->expects('prepare')
                ->with('select case when pg_is_in_recovery() then pg_last_wal_replay_lsn() else pg_current_wal_insert_lsn() end >= ?::pg_lsn')
                ->andReturn($this->commitIdStatement($immediateResult));
        }

        $this->assertSame($expected, $this->newCommitIdConnection($readPdo)->hasCommitId('0/16B3748', 5));
    }

    /**
     * Provide WAIT FOR LSN statuses and the resulting commit ID checks.
     *
     * @return array<string, array{0: string, 1: null|bool, 2: bool}>
     */
    public static function commitIdWaitStatuses(): array
    {
        return [
            'success' => ['success', null, true],
            'timeout' => ['timeout', null, false],
            'primary' => ['not in recovery', true, true],
        ];
    }

    public function testWaitingForACommitIdRequiresPostgres19OnTheReadConnection(): void
    {
        $readPdo = $this->commitIdReadPdo('18.4');
        $readPdo->shouldNotReceive('prepare');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PostgreSQL 19 or newer is required.');

        $this->newCommitIdConnection($readPdo)->hasCommitId('0/16B3748', 5);
    }

    /**
     * Create a read PDO reporting the given server version.
     */
    protected function commitIdReadPdo(string $version): PDO&MockInterface
    {
        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->andReturn($version);
        $pdo->shouldReceive('quote')->andReturnUsing(fn (string $value): string => "'{$value}'");

        return $pdo;
    }

    /**
     * Create a statement returning the given single-column result.
     */
    protected function commitIdStatement(bool|string $value): PDOStatement
    {
        $statement = m::mock(PDOStatement::class);
        $statement->shouldReceive('setFetchMode')->andReturnTrue();
        $statement->shouldReceive('bindValue')->andReturnTrue();
        $statement->expects('execute')->andReturnTrue();
        $statement->expects('fetchAll')->andReturn([(object) ['result' => $value]]);

        return $statement;
    }

    /**
     * Create a read / write connection whose write side fails the test when used.
     */
    protected function newCommitIdConnection(PDO $readPdo): PostgresConnection
    {
        $connection = new PostgresConnection(
            fn () => $this->fail('The write connection was used.'),
            'test_db',
            '',
            ['name' => 'test', 'driver' => 'pgsql'],
        );

        return $connection->setReadPdo($readPdo);
    }

    /**
     * Create a connection with the configured prepare mode.
     */
    protected function newConnection(bool|int $emulatePrepares): PostgresConnection
    {
        return new PostgresConnection(
            new DatabasePostgresConnectionPdoStub,
            'test_db',
            '',
            [
                'name' => 'test',
                'driver' => 'pgsql',
                'options' => [
                    PDO::ATTR_EMULATE_PREPARES => $emulatePrepares,
                ],
            ],
        );
    }
}

class DatabasePostgresConnectionPdoStub extends PDO
{
    public function __construct()
    {
    }
}
