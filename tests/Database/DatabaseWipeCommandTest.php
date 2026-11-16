<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Console\WipeCommand;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Schema\Builder;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DatabaseWipeCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testWipeCommandDropsSchemaObjectsAndDisconnectsConnection(): void
    {
        $schemaBuilder = Double::for(Builder::class);
        $schemaBuilder->expects('dropAllViews');
        $schemaBuilder->expects('dropAllTables');
        $schemaBuilder->expects('dropAllTypes');

        $connection = Double::for(Connection::class);
        $connection->expects('getSchemaBuilder')->times(3)->returns($schemaBuilder);
        $connection->expects('disconnect');

        $db = Double::for(DatabaseManager::class);
        $db->expects('connection')->times(4)->with('pgsql')->returns($connection);
        $db->expects('purge')->never();

        $app = new ApplicationDatabaseWipeStub([
            'db' => $db,
        ]);

        $command = new WipeCommand;
        $command->setHypervel($app);

        $code = $this->runCommand($command, [
            '--database' => 'pgsql',
            '--drop-views' => true,
            '--drop-types' => true,
        ]);

        $this->assertSame(0, $code);
    }

    public function testWipeCommandRoutesToMigrationsConnection(): void
    {
        $schemaBuilder = Double::for(Builder::class);
        $schemaBuilder->expects('dropAllTables');

        $connection = Double::for(Connection::class);
        $connection->expects('getSchemaBuilder')->returns($schemaBuilder);
        $connection->expects('disconnect');

        $db = Double::for(DatabaseManager::class);
        // db:wipe --database=pgsql-pooled should route to 'pgsql' because
        // pgsql-pooled has migrations_connection => 'pgsql'. Schema drops
        // need a direct (unpooled) connection.
        $db->expects('connection')->times(2)->with('pgsql')->returns($connection);
        $db->expects('purge')->never();

        $app = new ApplicationDatabaseWipeStub([
            'db' => $db,
        ]);
        $app->instance('config', new Repository([
            'database' => [
                'connections' => [
                    'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
                    'pgsql' => ['driver' => 'pgsql'],
                ],
            ],
        ]));

        $command = new WipeCommand;
        $command->setHypervel($app);

        $code = $this->runCommand($command, [
            '--database' => 'pgsql-pooled',
        ]);

        $this->assertSame(0, $code);
    }

    public function testWipeCommandRoutesThroughDefaultWhenNoDatabaseOptionGiven(): void
    {
        // Regression for the null-handling fix: db:wipe with no --database
        // should use the configured default — and if that default has a
        // migrations_connection, should route to it rather than dropping
        // tables on the pooled connection.
        $schemaBuilder = Double::for(Builder::class);
        $schemaBuilder->expects('dropAllTables');

        $connection = Double::for(Connection::class);
        $connection->expects('getSchemaBuilder')->returns($schemaBuilder);
        $connection->expects('disconnect');

        $db = Double::for(DatabaseManager::class);
        $db->expects('connection')->times(2)->with('pgsql')->returns($connection);
        $db->expects('purge')->never();

        $app = new ApplicationDatabaseWipeStub([
            'db' => $db,
        ]);
        $app->instance('config', new Repository([
            'database' => [
                'default' => 'pgsql-pooled',
                'connections' => [
                    'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
                    'pgsql' => ['driver' => 'pgsql'],
                ],
            ],
        ]));

        $command = new WipeCommand;
        $command->setHypervel($app);

        $code = $this->runCommand($command);

        $this->assertSame(0, $code);
    }

    public function testWipeCommandHonorsContextOverrideWhenNoDatabaseOptionGiven(): void
    {
        // End-to-end regression for "effective default" at the command level:
        // when an outer scope has set Context (e.g. via DB::usingConnection),
        // db:wipe with no --database must route to that Context target's
        // migrations_connection, not to the configured default's sibling.
        $schemaBuilder = Double::for(Builder::class);
        $schemaBuilder->expects('dropAllTables');

        $connection = Double::for(Connection::class);
        $connection->expects('getSchemaBuilder')->returns($schemaBuilder);
        $connection->expects('disconnect');

        $db = Double::for(DatabaseManager::class);
        $db->expects('connection')->times(2)->with('tenant-direct')->returns($connection);
        $db->expects('purge')->never();

        $app = new ApplicationDatabaseWipeStub([
            'db' => $db,
        ]);
        $app->instance('config', new Repository([
            'database' => [
                'default' => 'pgsql',
                'connections' => [
                    'pgsql' => ['driver' => 'pgsql'],
                    'tenant-pooled' => [
                        'driver' => 'pgsql',
                        'migrations_connection' => 'tenant-direct',
                    ],
                    'tenant-direct' => ['driver' => 'pgsql'],
                ],
            ],
        ]));

        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'tenant-pooled');

        $command = new WipeCommand;
        $command->setHypervel($app);

        $code = $this->runCommand($command);

        $this->assertSame(0, $code);
    }

    protected function runCommand($command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}

class ApplicationDatabaseWipeStub extends Application
{
    public function __construct(array $data = [])
    {
        $this->instance('env', 'development');

        foreach ($data as $abstract => $instance) {
            $this->instance($abstract, $instance);
        }

        static::setInstance($this);
    }

    public function environment(...$environments): bool|string
    {
        return 'development';
    }
}
