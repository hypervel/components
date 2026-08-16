<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Server\Commands\ServerReloadCommand;
use Hypervel\Server\Exceptions\InvalidArgumentException;
use Hypervel\Server\Exceptions\ServerException;
use Hypervel\Server\ServerReloader;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

class ServerReloadCommandTest extends TestCase
{
    public function testReloadCommandDelegatesToTheServerReloader(): void
    {
        $reloader = m::mock(ServerReloader::class);
        $reloader->expects('reload');
        $command = $this->reloadCommand($reloader);
        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Reloading workers...', $tester->getDisplay());
        $this->assertStringContainsString('Done.', $tester->getDisplay());
    }

    #[DataProvider('reloadExceptions')]
    public function testReloadCommandReportsReloadFailures(Throwable $exception): void
    {
        $reloader = m::mock(ServerReloader::class);
        $reloader->expects('reload')->andThrow($exception);
        $command = $this->reloadCommand($reloader);
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Reloading workers...', $tester->getDisplay());
        $this->assertStringContainsString($exception->getMessage(), $tester->getDisplay());
        $this->assertStringNotContainsString('Done.', $tester->getDisplay());
    }

    public static function reloadExceptions(): array
    {
        return [
            'unreadable PID file' => [new FileNotFoundException('File does not exist.')],
            'invalid PID file' => [new InvalidArgumentException('Invalid process ID.')],
            'signal failure' => [new ServerException('Unable to signal workers.')],
        ];
    }

    private function reloadCommand(ServerReloader $reloader): ServerReloadCommand
    {
        $command = new ServerReloadCommand($reloader);
        $command->setHypervel($this->app);

        return $command;
    }
}
