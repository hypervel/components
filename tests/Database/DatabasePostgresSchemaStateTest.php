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
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabasePostgresSchemaStateTest extends TestCase
{
    public function testDumpOutputFailureStopsTheProcess(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'echo "progress"; usleep(5000000);']);
        $connection = $this->createStub(PostgresConnection::class);
        $connection->method('getConfig')->willReturn([
            'host' => 'localhost', 'port' => 5432, 'username' => 'root', 'password' => '', 'database' => 'hypervel',
        ]);
        $state = new PostgresSchemaState($connection, processFactory: static fn () => $process);
        $failure = new RuntimeException('Unable to write dump output.');
        $state->withMigrationTable(null)->handleOutputUsing(static fn () => throw $failure);
        $caught = null;

        try {
            try {
                $state->dump($connection, '/unused-schema.sql');
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            $this->assertSame($failure, $caught);
            $this->assertFalse($process->isRunning());
        } finally {
            $process->stop(0);
        }
    }

    public function testBaseVariablesUseConfiguredConnectionByDefault(): void
    {
        $schemaState = new PostgresSchemaState(m::mock(PostgresConnection::class));

        $variables = (new ReflectionMethod(PostgresSchemaState::class, 'baseVariables'))->invoke($schemaState, [
            'host' => 'pooler-host',
            'port' => '6432',
            'username' => 'root',
            'password' => 'secret',
            'database' => 'hypervel',
            'sslmode' => 'prefer',
        ]);

        $this->assertSame([
            'HYPERVEL_LOAD_HOST' => 'pooler-host',
            'HYPERVEL_LOAD_PORT' => '6432',
            'HYPERVEL_LOAD_USER' => 'root',
            'PGPASSWORD' => 'secret',
            'PGSSLMODE' => 'prefer',
            'HYPERVEL_LOAD_DATABASE' => 'hypervel',
        ], $variables);
    }

    // REMOVED: Direct PDO configuration; schema operations use their resolved named connection.

    public function testBaseVariablesDoNotExportEmptySslMode(): void
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
        $path = 'database/schema/pg $name "quoted".sql';
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
            'HYPERVEL_LOAD_PATH' => $path,
        ])->andReturnSelf();

        $factoryCalledWith = null;
        $schemaState = new PostgresSchemaState(
            $connection,
            processFactory: function (...$args) use ($process, &$factoryCalledWith) {
                $factoryCalledWith = $args;

                return $process;
            }
        );

        $schemaState->dump($connection, $path);

        $this->assertSame(
            'pg_dump --no-owner --no-acl --host="${:HYPERVEL_LOAD_HOST}" --port="${:HYPERVEL_LOAD_PORT}" --username="${:HYPERVEL_LOAD_USER}" --dbname="${:HYPERVEL_LOAD_DATABASE}" --schema-only > "${:HYPERVEL_LOAD_PATH}"',
            $factoryCalledWith[0]
        );
    }

    public function testMigrationDataUsesLiteralPathAndTableArguments(): void
    {
        $schema = m::mock(PostgresBuilder::class);
        $schema->shouldReceive('hasTable')->once()->with('archive.Migration"History')->andReturnTrue();
        $schema->shouldReceive('parseSchemaAndTable')->once()->with('archive.Migration"History', true)->andReturn(['archive', 'Migration"History']);
        $connection = m::mock(PostgresConnection::class);
        $connection->shouldReceive('getSchemaBuilder')->twice()->andReturn($schema);
        $connection->shouldReceive('getTablePrefix')->once()->andReturn('pre.');
        $connection->shouldReceive('getConfig')->andReturn([
            'host' => 'localhost', 'username' => 'user', 'password' => '', 'database' => 'app',
        ]);

        $calls = [];
        $state = new PostgresSchemaState($connection, processFactory: function (string $command) use (&$calls): Process {
            $process = $this->createMock(Process::class);
            $process->expects($this->once())->method('mustRun')->willReturnCallback(
                static function (?callable $output, array $variables) use (&$calls, $command, $process): Process {
                    $calls[] = [$command, $variables];

                    return $process;
                },
            );

            return $process;
        });
        $path = 'schema $name "quoted".sql';

        $state->withMigrationTable('archive.Migration"History')->dump($connection, $path);

        $this->assertCount(2, $calls);
        $this->assertStringEndsWith(' --schema-only > "${:HYPERVEL_LOAD_PATH}"', $calls[0][0]);
        $this->assertStringEndsWith(' -t "${:HYPERVEL_LOAD_MIGRATION_TABLE}" --data-only >> "${:HYPERVEL_LOAD_PATH}"', $calls[1][0]);
        $this->assertSame($path, $calls[0][1]['HYPERVEL_LOAD_PATH']);
        $this->assertSame($path, $calls[1][1]['HYPERVEL_LOAD_PATH']);
        $this->assertSame('"archive"."pre.Migration""History"', $calls[1][1]['HYPERVEL_LOAD_MIGRATION_TABLE']);
    }

    public function testMigrationTableUsesTheActualSchemaUnlessExplicitlyQualified(): void
    {
        $connection = m::mock(PostgresConnection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(m::mock(PostgresGrammar::class));
        $connection->shouldReceive('scalar')
            ->twice()
            ->with('select current_schema()', [], false)
            ->andReturn('public', 'Schema.With.Dot');
        $connection->shouldReceive('getTablePrefix')->times(3)->andReturn('', '', 'prefix.with.dot_');

        $builder = new PostgresBuilder($connection);
        $connection->shouldReceive('getSchemaBuilder')->times(3)->andReturn($builder);

        $schemaState = new PostgresSchemaState($connection);
        $method = new ReflectionMethod($schemaState, 'getMigrationTable');

        $this->assertSame(
            '"public"."migrations"',
            $method->invoke($schemaState->withMigrationTable('migrations'))
        );
        $this->assertSame(
            '"archive"."migrations"',
            $method->invoke($schemaState->withMigrationTable('archive.migrations'))
        );
        $this->assertSame(
            '"Schema.With.Dot"."prefix.with.dot_Migration""History"',
            $method->invoke($schemaState->withMigrationTable('Migration"History')),
        );
    }
}
