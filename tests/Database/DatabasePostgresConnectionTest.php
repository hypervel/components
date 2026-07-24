<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use DateTimeImmutable;
use Hypervel\Database\PostgresConnection;
use Hypervel\Tests\TestCase;
use PDO;

class DatabasePostgresConnectionTest extends TestCase
{
    public function testPrepareBindingsConvertsBooleansToPostgresLiteralsWhenEmulatedPreparesAreEnabled(): void
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

    public function testPrepareBindingsConvertsBooleansForTruthyEmulatedPreparesConfiguration(): void
    {
        $connection = $this->newConnection(emulatePrepares: 1);

        $this->assertSame(['true', 'false'], $connection->prepareBindings([true, false]));
    }

    public function testPrepareBindingsUsesActiveReadConnectionConfiguration(): void
    {
        $connection = $this->newConnection(emulatePrepares: false, readWriteType: 'read');
        $connection->setReadPdoConfig([
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => true,
            ],
        ]);

        $this->assertSame(['true', 'false'], $connection->prepareBindings([true, false]));
    }

    public function testPrepareBindingsUsesWriteConnectionConfiguration(): void
    {
        $connection = $this->newConnection(emulatePrepares: true, readWriteType: 'write');
        $connection->setReadPdoConfig([
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ]);

        $this->assertSame(['true', 'false'], $connection->prepareBindings([true, false]));
    }

    public function testPrepareBindingsFallsBackToDefaultBooleanCastingWhenEmulatedPreparesAreDisabled(): void
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

    public function testEscapeUsesPostgresBooleanLiterals(): void
    {
        $connection = $this->newConnection(emulatePrepares: true);

        $this->assertSame('true', $connection->escape(true));
        $this->assertSame('false', $connection->escape(false));
    }

    protected function newConnection(bool|int $emulatePrepares, ?string $readWriteType = null): PostgresConnection
    {
        return new PostgresConnection(
            new DatabasePostgresConnectionPdoStub,
            'test_db',
            '',
            [
                'name' => 'test',
                'driver' => 'pgsql',
                PostgresConnection::READ_WRITE_TYPE_CONFIG_KEY => $readWriteType,
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
