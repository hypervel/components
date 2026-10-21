<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\CommandMutex;
use Hypervel\Database\Console\Migrations\MigrateMakeCommand;
use Hypervel\Database\Migrations\MigrationCreator;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DatabaseMigrationMakeCommandTest extends TestCase
{
    // REMOVED: make:migration no longer dumps Composer autoload files or
    // accepts Laravel's deprecated --fullpath option.

    public function testBasicCreateGivesCreatorProperArguments(): void
    {
        $app = new ApplicationDatabaseMigrationMakeStub;
        $app->useDatabasePath(__DIR__);
        $creator = m::mock(MigrationCreator::class);
        $command = new MigrateMakeCommand($creator);
        $command->setHypervel($app);
        $creator->expects('create')
            ->with('create_foo', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'foo', true)
            ->andReturn(__DIR__ . '/Fixtures/migrations/2021_04_23_110457_create_foo.php');

        $this->runCommand($command, ['name' => 'create_foo']);
    }

    public function testBasicCreateGivesCreatorProperArgumentsWhenNameIsStudlyCase(): void
    {
        $app = new ApplicationDatabaseMigrationMakeStub;
        $app->useDatabasePath(__DIR__);
        $creator = m::mock(MigrationCreator::class);
        $command = new MigrateMakeCommand($creator);
        $command->setHypervel($app);
        $creator->expects('create')
            ->with('create_foo', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'foo', true)
            ->andReturn(__DIR__ . '/Fixtures/migrations/2021_04_23_110457_create_foo.php');

        $this->runCommand($command, ['name' => 'CreateFoo']);
    }

    public function testBasicCreateGivesCreatorProperArgumentsWhenTableIsSet(): void
    {
        $app = new ApplicationDatabaseMigrationMakeStub;
        $app->useDatabasePath(__DIR__);
        $creator = m::mock(MigrationCreator::class);
        $command = new MigrateMakeCommand($creator);
        $command->setHypervel($app);
        $creator->expects('create')
            ->with('create_foo', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'users', true)
            ->andReturn(__DIR__ . '/Fixtures/migrations/2021_04_23_110457_create_foo.php');

        $this->runCommand($command, ['name' => 'create_foo', '--create' => 'users']);
    }

    public function testBasicCreateGivesCreatorProperArgumentsWhenCreateTablePatternIsFound(): void
    {
        $app = new ApplicationDatabaseMigrationMakeStub;
        $app->useDatabasePath(__DIR__);
        $creator = m::mock(MigrationCreator::class);
        $command = new MigrateMakeCommand($creator);
        $command->setHypervel($app);
        $creator->expects('create')
            ->with('create_users_table', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'users', true)
            ->andReturn(__DIR__ . '/Fixtures/migrations/2021_04_23_110457_create_users_table.php');

        $this->runCommand($command, ['name' => 'create_users_table']);
    }

    public function testCanSpecifyPathToCreateMigrationsIn(): void
    {
        $app = new ApplicationDatabaseMigrationMakeStub;
        $creator = m::mock(MigrationCreator::class);
        $command = new MigrateMakeCommand($creator);
        $command->setHypervel($app);
        $app->setBasePath('/home/hypervel');
        $creator->expects('create')
            ->with('create_foo', '/home/hypervel/vendor/hypervel-package/migrations', 'users', true)
            ->andReturn('/home/hypervel/vendor/hypervel-package/migrations/2021_04_23_110457_create_foo.php');
        $this->runCommand($command, ['name' => 'create_foo', '--path' => 'vendor/hypervel-package/migrations', '--create' => 'users']);
    }

    /**
     * Run the command with the given input.
     */
    protected function runCommand(MigrateMakeCommand $command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
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
