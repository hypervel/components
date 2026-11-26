<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Schema\Grammars\PostgresGrammar;
use Hypervel\Database\Schema\PostgresBuilder;
use Hypervel\Database\Schema\PostgresSchemaState;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabasePostgresSchemaStateTest extends TestCase
{
    use VerifiesDoubles;

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
        $schemaState = new PostgresSchemaState(Double::for(PostgresConnection::class)->strict());

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
        $connection = Double::for(PostgresConnection::class)->strict();
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

    public function testBaseVariablesAcceptOmittedCredentials(): void
    {
        $schemaState = new PostgresSchemaState(Double::for(PostgresConnection::class)->strict());

        $variables = (new ReflectionMethod($schemaState, 'baseVariables'))->invoke($schemaState, [
            'host' => '127.0.0.1',
            'database' => 'hypervel',
        ]);

        $this->assertSame(['', ''], [$variables['HYPERVEL_LOAD_USER'], $variables['PGPASSWORD']]);
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

        $schema = Double::for(PostgresBuilder::class)->strict();
        $schema->expects('hasTable')->with('migrations')->returns(false);

        $connection = Double::for(PostgresConnection::class)->strict();
        $connection->allows('getConfig')->returns($config);
        $connection->expects('getSchemaBuilder')->returns($schema);

        $process = Double::for(Process::class)->strict();
        $process->allows('stop')->with(0.0)->returns(0); // Symfony stops the process in its destructor.
        $process->expects('mustRun')->with(Argument::type('callable'), [
            'HYPERVEL_LOAD_HOST' => '127.0.0.1',
            'HYPERVEL_LOAD_PORT' => '5432',
            'HYPERVEL_LOAD_USER' => 'forge',
            'PGPASSWORD' => 'secret',
            'HYPERVEL_LOAD_DATABASE' => 'hypervel',
            'HYPERVEL_LOAD_PATH' => $path,
        ]);

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
        $schema = Double::for(PostgresBuilder::class)->strict();
        $schema->expects('hasTable')->with('archive.Migration"History')->returns(true);
        $schema->expects('parseSchemaAndTable')->with('archive.Migration"History', true)->returns(['archive', 'Migration"History']);
        $connection = Double::for(PostgresConnection::class)->strict();
        $connection->expects('getSchemaBuilder')->times(2)->returns($schema);
        $connection->expects('getTablePrefix')->returns('pre.');
        $connection->allows('getConfig')->returns([
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
        $connection = Double::for(PostgresConnection::class)->strict();
        $connection->expects('getSchemaGrammar')->returns(Double::for(PostgresGrammar::class)->strict());
        $connection->expects('scalar')
            ->times(2)
            ->with('select current_schema()', [], false)
            ->returns('public', 'Schema.With.Dot');
        $connection->expects('getTablePrefix')->times(3)->returns('', '', 'prefix.with.dot_');

        $builder = new PostgresBuilder($connection);
        $connection->expects('getSchemaBuilder')->times(3)->returns($builder);

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
