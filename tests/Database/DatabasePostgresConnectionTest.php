<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use DateTimeImmutable;
use Hypervel\Database\PostgresConnection;
use Hypervel\Tests\TestCase;
use PDO;

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
