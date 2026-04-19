<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Console\CommandMutex;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Console\WipeCommand;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DatabaseWipeCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);

        parent::tearDown();
    }

    public function testWipeCommandDropsSchemaObjectsAndPurgesConnection()
    {
        $schemaBuilder = m::mock();
        $schemaBuilder->shouldReceive('dropAllViews')->once();
        $schemaBuilder->shouldReceive('dropAllTables')->once();
        $schemaBuilder->shouldReceive('dropAllTypes')->once();

        $connection = m::mock();
        $connection->shouldReceive('getSchemaBuilder')->times(3)->andReturn($schemaBuilder);

        $db = m::mock();
        $db->shouldReceive('connection')->times(3)->with('pgsql')->andReturn($connection);
        $db->shouldReceive('purge')->once()->with('pgsql');

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

    public function testWipeCommandRoutesToMigrationsConnection()
    {
        $schemaBuilder = m::mock();
        $schemaBuilder->shouldReceive('dropAllTables')->once();

        $connection = m::mock();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schemaBuilder);

        $db = m::mock();
        // db:wipe --database=pgsql-pooled should route to 'pgsql' because
        // pgsql-pooled has migrations_connection => 'pgsql'. Schema drops
        // need a direct (unpooled) connection.
        $db->shouldReceive('connection')->once()->with('pgsql')->andReturn($connection);
        $db->shouldReceive('purge')->once()->with('pgsql');

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

    public function testWipeCommandRoutesThroughDefaultWhenNoDatabaseOptionGiven()
    {
        // Regression for the null-handling fix: db:wipe with no --database
        // should use the configured default — and if that default has a
        // migrations_connection, should route to it rather than dropping
        // tables on the pooled connection.
        $schemaBuilder = m::mock();
        $schemaBuilder->shouldReceive('dropAllTables')->once();

        $connection = m::mock();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schemaBuilder);

        $db = m::mock();
        $db->shouldReceive('connection')->once()->with('pgsql')->andReturn($connection);
        $db->shouldReceive('purge')->once()->with('pgsql');

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

    public function testWipeCommandHonorsContextOverrideWhenNoDatabaseOptionGiven()
    {
        // End-to-end regression for "effective default" at the command level:
        // when an outer scope has set Context (e.g. via DB::usingConnection),
        // db:wipe with no --database must route to that Context target's
        // migrations_connection, not to the configured default's sibling.
        $schemaBuilder = m::mock();
        $schemaBuilder->shouldReceive('dropAllTables')->once();

        $connection = m::mock();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schemaBuilder);

        $db = m::mock();
        $db->shouldReceive('connection')->once()->with('tenant-direct')->andReturn($connection);
        $db->shouldReceive('purge')->once()->with('tenant-direct');

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
        $mutex = m::mock(CommandMutex::class);
        $mutex->shouldReceive('create')->andReturn(true);
        $mutex->shouldReceive('release')->andReturn(true);
        $this->instance(CommandMutex::class, $mutex);
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
