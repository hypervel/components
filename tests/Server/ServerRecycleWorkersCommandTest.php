<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Server\Commands\ServerRecycleWorkersCommand;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Constant;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ServerRecycleWorkersCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testCommandThrowsWhenPidFileConfigIsMissing(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $filesystem->expects('get')->never();
        $command = $this->recycleCommand([], $filesystem);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Configuration value for key [server.settings.pid_file] must be a string, NULL given.');

        (new CommandTester($command))->execute([]);
    }

    public function testCommandFailsWhenPidFileCannotBeRead(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->throws(
            new FileNotFoundException('File does not exist.')
        );
        $command = $this->recycleCommand($this->settings(), $filesystem);
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString(
            'Unable to read the server PID file [/tmp/hypervel.pid].',
            $tester->getDisplay(),
        );
        $this->assertSame([], $command->signals);
    }

    #[DataProvider('invalidProcessIds')]
    public function testCommandRejectsInvalidProcessIds(string $contents): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->returns($contents);
        $command = $this->recycleCommand($this->settings(), $filesystem);
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

    public function testCommandSignalsTheServerToRecycleItsWorkers(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->returns("123\n");
        $command = $this->recycleCommand($this->settings(), $filesystem);
        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([[123, SIGUSR1]], $command->signals);
        $this->assertStringContainsString('Worker recycling requested.', $tester->getDisplay());
    }

    public function testCommandFailsWhenTheServerCannotBeSignaled(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->returns('123');
        $command = $this->recycleCommand($this->settings(), $filesystem);
        $command->signalResult = false;
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame([[123, SIGUSR1]], $command->signals);
        $this->assertStringContainsString('Unable to signal the server to recycle its workers.', $tester->getDisplay());
        $this->assertStringNotContainsString('Worker recycling requested.', $tester->getDisplay());
    }

    /**
     * Create a recycle command with the supplied configuration and filesystem.
     */
    private function recycleCommand(array $config, Filesystem $filesystem): ServerRecycleWorkersCommandTestCommand
    {
        $command = new ServerRecycleWorkersCommandTestCommand(new Repository($config), $filesystem);
        $command->setHypervel($this->app);

        return $command;
    }

    /**
     * Provide the server PID file setting.
     */
    private function settings(): array
    {
        return [
            'server' => [
                'settings' => [
                    Constant::OPTION_PID_FILE => '/tmp/hypervel.pid',
                ],
            ],
        ];
    }
}

class ServerRecycleWorkersCommandTestCommand extends ServerRecycleWorkersCommand
{
    /** @var list<array{int, int}> */
    public array $signals = [];

    public bool $signalResult = true;

    /**
     * Record the signal and return its configured result.
     */
    protected function signalProcess(int $pid, int $signal): bool
    {
        $this->signals[] = [$pid, $signal];

        return $this->signalResult;
    }
}
