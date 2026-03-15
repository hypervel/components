<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\CommandMutex;
use Hypervel\Database\Console\Migrations\MigrateMakeCommand;
use Hypervel\Database\Migrations\MigrationCreator;
use Hypervel\Foundation\Application;
use Hypervel\Support\Composer;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class DatabaseMigrationMakeCommandTest extends TestCase
{
    public function testBasicCreateDumpsAutoload()
    {
        $app = new ApplicationDatabaseMigrationMakeStub();
        $app->useDatabasePath(__DIR__);
        $command = new MigrateMakeCommand(
            $creator = m::mock(MigrationCreator::class),
            $composer = m::mock(Composer::class)
        );
        $command->setHypervel($app);
        $creator->shouldReceive('create')->once()
            ->with('create_foo', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'foo', true)
            ->andReturn(__DIR__ . '/migrations/2021_04_23_110457_create_foo.php');

        $this->runCommand($command, ['name' => 'create_foo']);
    }

    public function testBasicCreateGivesCreatorProperArguments()
    {
        $app = new ApplicationDatabaseMigrationMakeStub();
        $app->useDatabasePath(__DIR__);
        $command = new MigrateMakeCommand(
            $creator = m::mock(MigrationCreator::class),
            m::mock(Composer::class)->shouldIgnoreMissing()
        );
        $command->setHypervel($app);
        $creator->shouldReceive('create')->once()
            ->with('create_foo', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'foo', true)
            ->andReturn(__DIR__ . '/migrations/2021_04_23_110457_create_foo.php');

        $this->runCommand($command, ['name' => 'create_foo']);
    }

    public function testBasicCreateGivesCreatorProperArgumentsWhenNameIsStudlyCase()
    {
        $app = new ApplicationDatabaseMigrationMakeStub();
        $app->useDatabasePath(__DIR__);
        $command = new MigrateMakeCommand(
            $creator = m::mock(MigrationCreator::class),
            m::mock(Composer::class)->shouldIgnoreMissing()
        );
        $command->setHypervel($app);
        $creator->shouldReceive('create')->once()
            ->with('create_foo', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'foo', true)
            ->andReturn(__DIR__ . '/migrations/2021_04_23_110457_create_foo.php');

        $this->runCommand($command, ['name' => 'CreateFoo']);
    }

    public function testBasicCreateGivesCreatorProperArgumentsWhenTableIsSet()
    {
        $app = new ApplicationDatabaseMigrationMakeStub();
        $app->useDatabasePath(__DIR__);
        $command = new MigrateMakeCommand(
            $creator = m::mock(MigrationCreator::class),
            m::mock(Composer::class)->shouldIgnoreMissing()
        );
        $command->setHypervel($app);
        $creator->shouldReceive('create')->once()
            ->with('create_foo', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'users', true)
            ->andReturn(__DIR__ . '/migrations/2021_04_23_110457_create_foo.php');

        $this->runCommand($command, ['name' => 'create_foo', '--create' => 'users']);
    }

    public function testBasicCreateGivesCreatorProperArgumentsWhenCreateTablePatternIsFound()
    {
        $app = new ApplicationDatabaseMigrationMakeStub();
        $app->useDatabasePath(__DIR__);
        $command = new MigrateMakeCommand(
            $creator = m::mock(MigrationCreator::class),
            m::mock(Composer::class)->shouldIgnoreMissing()
        );
        $command->setHypervel($app);
        $creator->shouldReceive('create')->once()
            ->with('create_users_table', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'users', true)
            ->andReturn(__DIR__ . '/migrations/2021_04_23_110457_create_users_table.php');

        $this->runCommand($command, ['name' => 'create_users_table']);
    }

    public function testCanSpecifyPathToCreateMigrationsIn()
    {
        $app = new ApplicationDatabaseMigrationMakeStub();
        $command = new MigrateMakeCommand(
            $creator = m::mock(MigrationCreator::class),
            m::mock(Composer::class)->shouldIgnoreMissing()
        );
        $command->setHypervel($app);
        $app->setBasePath('/home/hypervel');
        $creator->shouldReceive('create')->once()
            ->with('create_foo', '/home/hypervel/vendor/hypervel-package/migrations', 'users', true)
            ->andReturn('/home/hypervel/vendor/hypervel-package/migrations/2021_04_23_110457_create_foo.php');
        $this->runCommand($command, ['name' => 'create_foo', '--path' => 'vendor/hypervel-package/migrations', '--create' => 'users']);
    }

    protected function runCommand($command, $input = [])
    {
        return $command->run(new ArrayInput($input), new NullOutput());
    }
}

class ApplicationDatabaseMigrationMakeStub extends Application
{
    public function __construct()
    {
        $mutex = m::mock(CommandMutex::class);
        $mutex->shouldReceive('create')->andReturn(true);
        $mutex->shouldReceive('release')->andReturn(true);
        $this->instance(CommandMutex::class, $mutex);
        $this->instance('env', 'development');

        static::setInstance($this);
    }

    public function environment(...$environments): bool|string
    {
        return 'development';
    }
}
