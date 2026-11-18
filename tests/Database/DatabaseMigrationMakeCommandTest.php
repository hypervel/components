<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Console\Migrations\MigrateMakeCommand;
use Hypervel\Database\Migrations\MigrationCreator;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DatabaseMigrationMakeCommandTest extends TestCase
{
    use VerifiesDoubles;

    // REMOVED: make:migration no longer dumps Composer autoload files or
    // accepts Laravel's deprecated --fullpath option.

    public function testBasicCreateGivesCreatorProperArguments(): void
    {
        $app = new ApplicationDatabaseMigrationMakeStub;
        $app->useDatabasePath(__DIR__);
        $creator = Double::for(MigrationCreator::class)->strict();
        $command = new MigrateMakeCommand($creator);
        $command->setHypervel($app);
        $creator->expects('create')
            ->with('create_foo', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'foo', true)
            ->returns(__DIR__ . '/Fixtures/migrations/2021_04_23_110457_create_foo.php');

        $this->runCommand($command, ['name' => 'create_foo']);
    }

    public function testBasicCreateGivesCreatorProperArgumentsWhenNameIsStudlyCase(): void
    {
        $app = new ApplicationDatabaseMigrationMakeStub;
        $app->useDatabasePath(__DIR__);
        $creator = Double::for(MigrationCreator::class)->strict();
        $command = new MigrateMakeCommand($creator);
        $command->setHypervel($app);
        $creator->expects('create')
            ->with('create_foo', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'foo', true)
            ->returns(__DIR__ . '/Fixtures/migrations/2021_04_23_110457_create_foo.php');

        $this->runCommand($command, ['name' => 'CreateFoo']);
    }

    public function testBasicCreateGivesCreatorProperArgumentsWhenTableIsSet(): void
    {
        $app = new ApplicationDatabaseMigrationMakeStub;
        $app->useDatabasePath(__DIR__);
        $creator = Double::for(MigrationCreator::class)->strict();
        $command = new MigrateMakeCommand($creator);
        $command->setHypervel($app);
        $creator->expects('create')
            ->with('create_foo', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'users', true)
            ->returns(__DIR__ . '/Fixtures/migrations/2021_04_23_110457_create_foo.php');

        $this->runCommand($command, ['name' => 'create_foo', '--create' => 'users']);
    }

    public function testBasicCreateGivesCreatorProperArgumentsWhenCreateTablePatternIsFound(): void
    {
        $app = new ApplicationDatabaseMigrationMakeStub;
        $app->useDatabasePath(__DIR__);
        $creator = Double::for(MigrationCreator::class)->strict();
        $command = new MigrateMakeCommand($creator);
        $command->setHypervel($app);
        $creator->expects('create')
            ->with('create_users_table', __DIR__ . DIRECTORY_SEPARATOR . 'migrations', 'users', true)
            ->returns(__DIR__ . '/Fixtures/migrations/2021_04_23_110457_create_users_table.php');

        $this->runCommand($command, ['name' => 'create_users_table']);
    }

    public function testCanSpecifyPathToCreateMigrationsIn(): void
    {
        $app = new ApplicationDatabaseMigrationMakeStub;
        $creator = Double::for(MigrationCreator::class)->strict();
        $command = new MigrateMakeCommand($creator);
        $command->setHypervel($app);
        $app->setBasePath('/home/hypervel');
        $creator->expects('create')
            ->with('create_foo', '/home/hypervel/vendor/hypervel-package/migrations', 'users', true)
            ->returns('/home/hypervel/vendor/hypervel-package/migrations/2021_04_23_110457_create_foo.php');
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
        $this->instance('env', 'development');

        static::setInstance($this);
    }

    public function environment(...$environments): bool|string
    {
        return 'development';
    }
}
