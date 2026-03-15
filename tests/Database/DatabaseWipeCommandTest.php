<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\CommandMutex;
use Hypervel\Database\Console\WipeCommand;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class DatabaseWipeCommandTest extends TestCase
{
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

        $command = new WipeCommand();
        $command->setHypervel($app);

        $code = $this->runCommand($command, [
            '--database' => 'pgsql',
            '--drop-views' => true,
            '--drop-types' => true,
        ]);

        $this->assertSame(0, $code);
    }

    protected function runCommand($command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput());
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
