<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\Database\Console\Migrations\ResetCommand;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use UnitEnum;

class DatabaseMigrationResetCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testResetCommandCallsMigratorWithProperArguments(): void
    {
        $migrator = Double::for(Migrator::class)->strict();
        $command = new ResetCommand($migrator);
        $app = new ApplicationDatabaseResetStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->returns([]);
        $migrator->expects('usingConnection')->with(null, Argument::type(Closure::class))->resolves(function (?string $connection, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('repositoryExists')->returns(true);
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('reset')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], false);

        $this->runCommand($command);
    }

    public function testResetCommandCanBePretended(): void
    {
        $migrator = Double::for(Migrator::class)->strict();
        $command = new ResetCommand($migrator);
        $app = new ApplicationDatabaseResetStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->returns([]);
        $migrator->expects('usingConnection')->with('foo', Argument::type(Closure::class))->resolves(function (?string $connection, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('repositoryExists')->returns(true);
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('reset')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], true);

        $this->runCommand($command, ['--pretend' => true, '--database' => 'foo']);
    }

    public function testResetCommandExitsWhenProhibited(): void
    {
        $migrator = Double::for(Migrator::class)->strict();
        $command = new ResetCommand($migrator);

        $app = new ApplicationDatabaseResetStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);

        ResetCommand::prohibit();

        $code = $this->runCommand($command);

        $this->assertSame(1, $code);

        $migrator->received('paths')->never();
    }

    /**
     * Run the reset command.
     */
    protected function runCommand(ResetCommand $command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}

class ApplicationDatabaseResetStub extends Application
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
