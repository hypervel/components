<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\Config\Repository;
use Hypervel\Console\OutputStyle;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Console\DbCommand;
use Hypervel\Database\DatabaseCliConfiguration;
use Hypervel\Database\DatabaseCliManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

// Process overloads must never enter the shared PHPUnit worker.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class DatabaseDbCommandLaunchTest extends TestCase
{
    public function testBuiltInLaunchUsesThePublicAndProtectedOverrides(): void
    {
        $command = new class extends DbCommand {
            /**
             * Select the application's client executable.
             */
            public function getCommand(array $connection): string
            {
                return 'custom-' . parent::getCommand($connection);
            }

            /**
             * Add an application-wide client argument.
             */
            public function commandArguments(array $connection): array
            {
                return [...parent::commandArguments($connection), '--interactive'];
            }

            /**
             * Supply the application's client environment.
             */
            public function commandEnvironment(array $connection): ?array
            {
                return ['MYSQL_PWD' => 'application-secret'];
            }

            /**
             * Customize the MySQL-specific client arguments.
             */
            protected function getMysqlArguments(array $connection): array
            {
                return [...parent::getMysqlArguments($connection), '--local-infile=0'];
            }
        };

        $output = $this->prepareCommand($command, new DatabaseCliManager, [
            'driver' => 'mysql',
            'host' => 'database-host',
            'port' => 3306,
            'username' => 'root',
            'password' => '',
            'database' => 'app',
        ]);
        $this->expectProcess([
            'custom-mysql', '--host=database-host', '--port=3306', '--user=root',
            'app', '--local-infile=0', '--interactive',
        ], ['MYSQL_PWD' => 'application-secret']);

        $this->assertSame(0, $command->handle());
        $this->assertSame('connected', $output->fetch());
    }

    public function testHostlessExtensionResolvesOnceForTheLaunch(): void
    {
        $manager = new DatabaseCliManager;
        $connection = ['driver' => 'custom', 'database' => 'analytics'];
        $configuration = new DatabaseCliConfiguration(
            'custom-client',
            ['--database', 'analytics'],
            ['CLIENT_PASSWORD' => 'secret'],
        );
        $calls = 0;
        $manager->extend('custom', function (array $resolved) use ($connection, $configuration, &$calls): DatabaseCliConfiguration {
            ++$calls;
            $this->assertSame($connection, $resolved);

            return $configuration;
        });

        $command = new DbCommand;
        $output = $this->prepareCommand($command, $manager, $connection);
        $this->expectProcess(['custom-client', '--database', 'analytics'], ['CLIENT_PASSWORD' => 'secret']);

        $this->assertSame(0, $command->handle());
        $this->assertSame(1, $calls);
        $this->assertSame('connected', $output->fetch());
    }

    /**
     * Prepare the command's application and console input/output.
     */
    private function prepareCommand(DbCommand $command, DatabaseCliManager $manager, array $connection): BufferedOutput
    {
        $application = m::mock(Application::class);
        $application->shouldReceive('make')->once()->with(DatabaseCliManager::class)->andReturn($manager);
        $application->shouldReceive('make')->once()->with('config')->andReturn(new Repository([
            'database' => ['default' => 'testing', 'connections' => ['testing' => $connection]],
        ]));

        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());
        $output = new BufferedOutput;
        $command->setHypervel($application);
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));

        return $output;
    }

    /**
     * Verify the process launch without opening a terminal or client.
     */
    private function expectProcess(array $arguments, array $environment): void
    {
        $execution = m::mock();
        $execution->shouldReceive('setTty')->once()->with(true)->andReturnSelf();
        $execution->shouldReceive('mustRun')->once()->with(m::type(Closure::class))
            ->andReturnUsing(function (Closure $callback) use ($execution): m\MockInterface {
                $callback('out', 'connected');

                return $execution;
            });

        $process = m::mock('overload:' . Process::class);
        $process->shouldReceive('__construct')->once()->with($arguments, null, $environment);
        $process->shouldReceive('setTimeout')->once()->with(null)->andReturn($execution);
    }
}
