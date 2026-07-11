<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Testbench\TestCase;
use Hypervel\Watcher\ServerRestartStrategy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\NullOutput;

class ServerRestartStrategyTest extends TestCase
{
    public function testConstructorThrowsWhenPidFileNotConfigured(): void
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '', 'daemonize' => false]],
            'watcher' => ['bin' => PHP_BINARY, 'command' => ['artisan', 'serve']],
        ]));

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('The config of pid_file is not found.');

        new ServerRestartStrategy($this->app, new NullOutput);
    }

    public function testConstructorThrowsWhenDaemonizeIsTrue(): void
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '/tmp/test.pid', 'daemonize' => true]],
            'watcher' => ['bin' => PHP_BINARY, 'command' => ['artisan', 'serve']],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Please set `server.settings.daemonize` to false');

        new ServerRestartStrategy($this->app, new NullOutput);
    }

    public function testConstructorSucceedsWithValidConfig(): void
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '/tmp/test.pid', 'daemonize' => false]],
            'watcher' => ['bin' => PHP_BINARY, 'command' => ['artisan', 'serve']],
        ]));

        $strategy = new ServerRestartStrategy($this->app, new NullOutput);

        $this->assertInstanceOf(ServerRestartStrategy::class, $strategy);
    }

    public function testServerCommandPreservesEveryArgumentLiterally(): void
    {
        $bin = "/tmp/php binary'$(ignored)";
        $script = "artisan script'$(ignored)";
        $arguments = ['serve', '--host=example value', "--name=a'b", '$(ignored)'];

        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '/tmp/test.pid', 'daemonize' => false]],
            'watcher' => ['bin' => $bin, 'command' => [$script, ...$arguments]],
        ]));

        $strategy = new class($this->app, new NullOutput) extends ServerRestartStrategy {
            public function commandForTest(): array
            {
                return $this->serverCommand();
            }
        };

        $this->assertSame([$bin, base_path($script), ...$arguments], $strategy->commandForTest());
    }

    public function testConstructorRejectsAnEmptyExecutablePath(): void
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '/tmp/test.pid', 'daemonize' => false]],
            'watcher' => ['bin' => '', 'command' => ['artisan', 'serve']],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The watcher.bin configuration value must be a non-empty executable path.');

        new ServerRestartStrategy($this->app, new NullOutput);
    }

    #[DataProvider('invalidCommandProvider')]
    public function testConstructorRejectsInvalidCommandLists(array $command): void
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '/tmp/test.pid', 'daemonize' => false]],
            'watcher' => ['bin' => PHP_BINARY, 'command' => $command],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The watcher.command configuration value must be a non-empty list of non-empty strings.');

        new ServerRestartStrategy($this->app, new NullOutput);
    }

    public static function invalidCommandProvider(): array
    {
        return [
            'empty' => [[]],
            'associative' => [['script' => 'artisan']],
            'empty argument' => [['artisan', '']],
            'non-string argument' => [['artisan', 1]],
        ];
    }
}
