<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Schema\Grammars\PostgresGrammar;
use Hypervel\Database\Schema\PostgresBuilder;
use Hypervel\Database\Schema\PostgresSchemaState;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;
use Symfony\Component\Process\Process;

class DatabasePostgresSchemaStateTest extends TestCase
{
    public function testBaseVariablesNormalizeEnvironmentValues(): void
    {
        $connection = m::mock(PostgresConnection::class);
        $schemaState = new PostgresSchemaState($connection);

        $method = new ReflectionMethod($schemaState, 'baseVariables');

        $this->assertSame([
            'HYPERVEL_LOAD_HOST' => '127.0.0.1',
            'HYPERVEL_LOAD_PORT' => '5432',
            'HYPERVEL_LOAD_USER' => 'forge',
            'PGPASSWORD' => '12345',
            'HYPERVEL_LOAD_DATABASE' => 'hypervel',
        ], $method->invoke($schemaState, [
            'host' => ['127.0.0.1', '127.0.0.2'],
            'port' => 5432,
            'username' => 'forge',
            'password' => 12345,
            'database' => 'hypervel',
        ]));
    }

    public function testDumpPassesSchemaPathToProcessEnvironment(): void
    {
        $config = [
            'host' => '127.0.0.1',
            'port' => 5432,
            'username' => 'forge',
            'password' => 'secret',
            'database' => 'hypervel',
        ];

        $schema = m::mock(PostgresBuilder::class);
        $schema->shouldReceive('hasTable')->once()->with('migrations')->andReturnFalse();

        $connection = m::mock(PostgresConnection::class);
        $connection->shouldReceive('getConfig')->andReturn($config);
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);

        $process = m::mock(Process::class);
        $process->shouldReceive('mustRun')->once()->with(m::type('callable'), [
            'HYPERVEL_LOAD_HOST' => '127.0.0.1',
            'HYPERVEL_LOAD_PORT' => '5432',
            'HYPERVEL_LOAD_USER' => 'forge',
            'PGPASSWORD' => 'secret',
            'HYPERVEL_LOAD_DATABASE' => 'hypervel',
            'HYPERVEL_LOAD_PATH' => 'database/schema/pgsql-schema.sql',
        ])->andReturnSelf();

        $factoryCalledWith = null;
        $schemaState = new PostgresSchemaState(
            $connection,
            processFactory: function (...$args) use ($process, &$factoryCalledWith) {
                $factoryCalledWith = $args;

                return $process;
            }
        );

        $schemaState->dump($connection, 'database/schema/pgsql-schema.sql');

        $this->assertSame(
            'pg_dump --no-owner --no-acl --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}" --username="${:HYPERVEL_LOAD_USER}" --dbname="${:HYPERVEL_LOAD_DATABASE}" --schema-only > database/schema/pgsql-schema.sql',
            $factoryCalledWith[0]
        );
    }

    public function testMigrationTableUsesTheActualSchemaUnlessExplicitlyQualified(): void
    {
        $connection = m::mock(PostgresConnection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(m::mock(PostgresGrammar::class));
        $connection->shouldReceive('scalar')
            ->once()
            ->with('select current_schema()', [], false)
            ->andReturn('public');
        $connection->shouldReceive('getTablePrefix')->twice()->andReturn('');

        $builder = new PostgresBuilder($connection);
        $connection->shouldReceive('getSchemaBuilder')->twice()->andReturn($builder);

        $schemaState = new PostgresSchemaState($connection);
        $method = new ReflectionMethod($schemaState, 'getMigrationTable');

        $this->assertSame(
            'public.migrations',
            $method->invoke($schemaState->withMigrationTable('migrations'))
        );
        $this->assertSame(
            'archive.migrations',
            $method->invoke($schemaState->withMigrationTable('archive.migrations'))
        );
    }
}
