<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Console\Migrations\RollbackCommand;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use UnitEnum;

class DatabaseMigrationRollbackCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testRollbackCommandCallsMigratorWithProperArguments(): void
    {
        $migrator = Double::for(Migrator::class)->strict();
        $command = new RollbackCommand($migrator);
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->returns([]);
        $migrator->expects('usingConnection')->resolves(function (?string $name, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('rollback')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => 0, 'batch' => 0]);

        $this->runCommand($command);
    }

    public function testRollbackCommandCallsMigratorWithStepOption(): void
    {
        $migrator = Double::for(Migrator::class)->strict();
        $command = new RollbackCommand($migrator);
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->returns([]);
        $migrator->expects('usingConnection')->resolves(function (?string $name, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('rollback')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => 2, 'batch' => 0]);

        $this->runCommand($command, ['--step' => 2]);
    }

    public function testRollbackCommandCanBePretended(): void
    {
        $migrator = Double::for(Migrator::class)->strict();
        $command = new RollbackCommand($migrator);
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->returns([]);
        $migrator->expects('usingConnection')->resolves(function (?string $name, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('rollback')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => true, 'step' => 0, 'batch' => 0]);

        $this->runCommand($command, ['--pretend' => true, '--database' => 'foo']);
    }

    public function testRollbackCommandCanBePretendedWithStepOption(): void
    {
        $migrator = Double::for(Migrator::class)->strict();
        $command = new RollbackCommand($migrator);
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->returns([]);
        $migrator->expects('usingConnection')->resolves(function (?string $name, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('rollback')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => true, 'step' => 2, 'batch' => 0]);

        $this->runCommand($command, ['--pretend' => true, '--database' => 'foo', '--step' => 2]);
    }

    public function testRollbackCommandCallsMigratorWithBatchOption(): void
    {
        $migrator = Double::for(Migrator::class)->strict();
        $command = new RollbackCommand($migrator);
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->returns([]);
        $migrator->expects('usingConnection')->resolves(function (?string $name, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('rollback')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => 0, 'batch' => 3]);

        $this->runCommand($command, ['--batch' => 3]);
    }

    /**
     * Run the rollback command.
     */
    protected function runCommand(RollbackCommand $command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}

class ApplicationDatabaseRollbackStub extends Application
{
    /**
     * Create a new test application instance.
     */
    public function __construct(array $data = [])
    {
        $this->instance('env', 'development');

        foreach ($data as $abstract => $instance) {
            $this->instance($abstract, $instance);
        }

        static::setInstance($this);
    }

    /**
     * Get the application environment.
     */
    public function environment(UnitEnum|array|string ...$environments): bool|string
    {
        return 'development';
    }
}
