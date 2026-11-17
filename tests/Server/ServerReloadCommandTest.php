<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Server\Commands\ServerReloadCommand;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Constant;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ServerReloadCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testReloadCommandThrowsCommandExceptionWhenPidFileConfigIsMissing(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $filesystem->expects('get')->never();
        $command = $this->reloadCommand([], $filesystem);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Configuration value for key [server.settings.pid_file] must be a string, NULL given.');

        (new CommandTester($command))->execute([]);
    }

    public function testReloadCommandFailsWhenPidFileCannotBeRead(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->throws(
            new FileNotFoundException('File does not exist.')
        );
        $command = $this->reloadCommand($this->settings(), $filesystem);
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString(
            'Unable to read the server PID file [/tmp/hypervel.pid].',
            $tester->getDisplay(),
        );
        $this->assertSame([], $command->signals);
    }

    #[DataProvider('invalidProcessIds')]
    public function testReloadCommandRejectsInvalidProcessIds(string $contents): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->returns($contents);
        $command = $this->reloadCommand($this->settings(), $filesystem);
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString(
            'The server PID file [/tmp/hypervel.pid] does not contain a valid process ID.',
            $tester->getDisplay(),
        );
        $this->assertSame([], $command->signals);
    }

    /**
     * Provide invalid PID-file contents.
     */
    public static function invalidProcessIds(): array
    {
        return [
            'empty' => [''],
            'whitespace' => [" \n"],
            'malformed' => ['123abc'],
            'zero' => ['0'],
            'negative' => ['-123'],
            'overflow' => ['999999999999999999999999999999'],
        ];
    }

    public function testReloadCommandSignalsEventWorkers(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->returns("123\n");
        $command = $this->reloadCommand($this->settings(), $filesystem);
        $command->returnSignalResults(true);
        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([[123, SIGUSR1]], $command->signals);
        $this->assertStringContainsString('Reloading workers...', $tester->getDisplay());
        $this->assertStringNotContainsString('Reloading task workers...', $tester->getDisplay());
        $this->assertStringContainsString('Done.', $tester->getDisplay());
    }

    public function testReloadCommandSignalsEventAndTaskWorkers(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->returns('123');
        $command = $this->reloadCommand($this->settings(taskWorkers: 2), $filesystem);
        $command->returnSignalResults(true, true);
        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([[123, SIGUSR1], [123, SIGUSR2]], $command->signals);
        $this->assertStringContainsString('Reloading task workers...', $tester->getDisplay());
        $this->assertStringContainsString('Done.', $tester->getDisplay());
    }

    public function testReloadCommandFailsWhenEventWorkersCannotBeSignaled(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->returns('123');
        $command = $this->reloadCommand($this->settings(taskWorkers: 2), $filesystem);
        $command->returnSignalResults(false);
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame([[123, SIGUSR1]], $command->signals);
        $this->assertStringContainsString('Unable to reload workers.', $tester->getDisplay());
        $this->assertStringNotContainsString('Reloading task workers...', $tester->getDisplay());
        $this->assertStringNotContainsString('Done.', $tester->getDisplay());
    }

    public function testReloadCommandFailsWhenTaskWorkersCannotBeSignaled(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->returns('123');
        $command = $this->reloadCommand($this->settings(taskWorkers: 2), $filesystem);
        $command->returnSignalResults(true, false);
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame([[123, SIGUSR1], [123, SIGUSR2]], $command->signals);
        $this->assertStringContainsString('Unable to reload task workers.', $tester->getDisplay());
        $this->assertStringNotContainsString('Done.', $tester->getDisplay());
    }

    /**
     * Create a reload command with the supplied configuration and filesystem.
     */
    private function reloadCommand(array $config, Filesystem $filesystem): ServerReloadCommandTestCommand
    {
        $command = new ServerReloadCommandTestCommand(new Repository($config), $filesystem);
        $command->setHypervel($this->app);

        return $command;
    }

    /**
     * Provide server PID and worker settings.
     */
    private function settings(int $taskWorkers = 0): array
    {
        return [
            'server' => [
                'settings' => [
                    Constant::OPTION_PID_FILE => '/tmp/hypervel.pid',
                    Constant::OPTION_TASK_WORKER_NUM => $taskWorkers,
                ],
            ],
        ];
    }
}

class ServerReloadCommandTestCommand extends ServerReloadCommand
{
    /** @var list<array{int, int}> */
    public array $signals = [];

    /** @var list<bool> */
    private array $signalResults = [];

    /**
     * Set the results returned by successive signal attempts.
     */
    public function returnSignalResults(bool ...$results): void
    {
        $this->signalResults = $results;
    }

    /**
     * Record the signal and return its configured result.
     */
    protected function signalProcess(int $pid, int $signal): bool
    {
        $this->signals[] = [$pid, $signal];

        return array_shift($this->signalResults) ?? true;
    }
}
