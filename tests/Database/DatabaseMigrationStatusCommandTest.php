<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Console\Migrations\StatusCommand;
use Hypervel\Database\Migrations\MigrationRepositoryInterface;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DatabaseMigrationStatusCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testPendingOptionReturnsConfiguredExitCodeAsInteger(): void
    {
        $app = new ApplicationDatabaseStatusStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new StatusCommand($migrator = Double::for(Migrator::class));
        $command->setHypervel($app);
        $repository = Double::for(MigrationRepositoryInterface::class);
        $migrator->expects('usingConnection')->resolves(function ($name, $callback) {
            return $callback();
        });
        $migrator->expects('repositoryExists')->returns(true);
        $migrator->expects('getRepository')->times(2)->returns($repository);
        $repository->expects('getRan')->returns([]);
        $repository->expects('getMigrationBatches')->returns([]);
        $migrator->expects('paths')->returns([]);
        $migrator->expects('getMigrationFiles')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'])->returns([
            '/tmp/2024_01_01_000000_create_users_table.php',
        ]);
        $migrator->expects('getMigrationName')->with('/tmp/2024_01_01_000000_create_users_table.php')->returns('2024_01_01_000000_create_users_table');
        $migrator->expects('shouldSkip')->with('/tmp/2024_01_01_000000_create_users_table.php')->returns(false);

        $code = $this->runCommand($command, ['--pending' => '5']);

        $this->assertSame(5, $code);
    }

    protected function runCommand($command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}

class ApplicationDatabaseStatusStub extends Application
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
