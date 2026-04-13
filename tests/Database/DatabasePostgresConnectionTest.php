<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use DateTimeImmutable;
use Hypervel\Database\PostgresConnection;
use Hypervel\Tests\TestCase;
use PDO;

/**
 * @internal
 * @coversNothing
 */
class DatabasePostgresConnectionTest extends TestCase
{
    public function testPrepareBindingsConvertsBooleansToPostgresLiteralsWhenEmulatedPreparesAreEnabled()
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

    public function testPrepareBindingsFallsBackToDefaultBooleanCastingWhenEmulatedPreparesAreDisabled()
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

    public function testEscapeUsesPostgresBooleanLiterals()
    {
        $connection = $this->newConnection(emulatePrepares: true);

        $this->assertSame('true', $connection->escape(true));
        $this->assertSame('false', $connection->escape(false));
    }

    protected function newConnection(bool $emulatePrepares): PostgresConnection
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
