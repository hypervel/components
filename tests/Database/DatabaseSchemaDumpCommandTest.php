<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Console\DumpCommand;
use Hypervel\Database\Schema\SchemaState;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseSchemaDumpCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testDumpCommandExitsWhenProhibited(): void
    {
        $path = database_path('schema/prohibited-dump.sql');

        $this->beforeApplicationDestroyed(fn () => $this->app->make('files')->delete($path));

        $this->assertDirectoryExists(database_path('migrations'));

        DumpCommand::prohibit();

        $this->artisan('schema:dump', ['--path' => $path, '--prune' => true])
            ->expectsOutputToContain('This command is prohibited from running in this environment.')
            ->assertFailed();

        $this->assertFileDoesNotExist($path);
        $this->assertDirectoryExists(database_path('migrations'));
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('connectionSelectionProvider')]
    public function testDumpUsesAndIsNamedAfterTheMigrationConnection(array $options): void
    {
        $schemaPath = database_path('schema');

        if (! is_dir($schemaPath)) {
            $this->beforeApplicationDestroyed(fn () => $this->app->make('files')->deleteDirectory($schemaPath));
        }

        config([
            'database.default' => 'pgsql-pooled',
            'database.connections.pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
            'database.connections.pgsql' => ['driver' => 'pgsql'],
        ]);

        $connection = Double::for(Connection::class);
        $state = Double::for(SchemaState::class);
        $connection->allows('getName')->returns('pgsql');
        $connection->allows('getSchemaState')->returns($state);
        $state->allows('withMigrationTable');
        $state->allows('handleOutputUsing');
        $state->expects('dump')->with($connection, database_path('schema/pgsql-schema.sql'));

        $connections = Double::for(ConnectionResolverInterface::class);
        $connections->expects('connection')->with('pgsql')->returns($connection);
        $this->app->instance(ConnectionResolverInterface::class, $connections);

        $this->artisan('schema:dump', $options)->assertSuccessful();
    }

    /**
     * Provide the default and explicit selection of a connection that routes its migrations elsewhere.
     *
     * @return iterable<string, array{array<string, string>}>
     */
    public static function connectionSelectionProvider(): iterable
    {
        yield 'default connection' => [[]];
        yield 'explicit connection' => [['--database' => 'pgsql-pooled']];
    }
}
