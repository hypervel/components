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
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

class DatabaseDbCommandLaunchTest extends TestCase
{
    use VerifiesDoubles;

    private DoubleInterface $application;

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
        $this->application = Double::for(Application::class, override: true);
        $this->application->expects('make')->with(DatabaseCliManager::class)->returns($manager);
        $this->application->expects('make')->with('config')->returns(new Repository([
            'database' => ['default' => 'testing', 'connections' => ['testing' => $connection]],
        ]));

        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());
        $output = new BufferedOutput;
        $command->setHypervel($this->application->instance());
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));

        return $output;
    }

    /**
     * Verify the process launch without opening a terminal or client.
     */
    private function expectProcess(array $arguments, array $environment): void
    {
        $process = Double::for(Process::class);
        $process->expects('setTimeout')->with(null);
        $process->expects('setTty')->with(true);
        $process->expects('mustRun')->with(Argument::type(Closure::class))
            ->resolves(function (Closure $callback) use ($process): Process {
                $callback('out', 'connected');

                return $process;
            });

        $this->application->expects('make')->with(Process::class, [
            'command' => $arguments,
            'env' => $environment,
        ])->returns($process);
    }
}
