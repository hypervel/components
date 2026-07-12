<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Hypervel\Watcher\Events\BeforeServerRestart;
use Hypervel\Watcher\ServerRestartStrategy;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

    #[DataProvider('invalidPidProvider')]
    public function testStopNeverSignalsAnInvalidPid(string $contents): void
    {
        $strategy = $this->createProbeStrategy();
        $strategy->useFilesystem(new PidFileFilesystem(true, $contents));

        $strategy->stop();

        $this->assertSame([], $strategy->signals);
    }

    public static function invalidPidProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => [" \n\t"],
            'zero' => ['0'],
            'negative' => ['-1'],
            'non-numeric' => ['not-a-pid'],
            'numeric prefix' => ['123garbage'],
        ];
    }

    public function testStopProbesButDoesNotTerminateAnAlreadyStoppedProcess(): void
    {
        $strategy = $this->createProbeStrategy();
        $strategy->useFilesystem(new PidFileFilesystem(true, '123'));
        $strategy->processIsRunning = false;

        $strategy->stop();

        $this->assertSame([[123, 0]], $strategy->signals);
    }

    public function testStopDispatchesAnIntegerPidAndTerminatesALiveProcess(): void
    {
        $strategy = $this->createProbeStrategy();
        $strategy->useFilesystem(new PidFileFilesystem(true, " 123\n"));
        $events = [];
        $this->app->make('events')->listen(
            BeforeServerRestart::class,
            function (BeforeServerRestart $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $strategy->stop();

        $this->assertSame([[123, 0], [123, SIGTERM]], $strategy->signals);
        $this->assertCount(1, $events);
        $this->assertSame(123, $events[0]->pid);
    }

    public function testStopIsIdempotentWhenThePidFileDoesNotExist(): void
    {
        $strategy = $this->createProbeStrategy();
        $strategy->useFilesystem(new PidFileFilesystem(false, ''));

        $strategy->stop();
        $strategy->stop();

        $this->assertSame([], $strategy->signals);
    }

    public function testOpenFailureRestoresTheLaunchTokenAndAllowsRetry(): void
    {
        $failure = m::mock(ExceptionHandlerContract::class);
        $failure->shouldReceive('report')
            ->twice()
            ->with(m::on(
                fn (mixed $exception): bool => $exception instanceof RuntimeException
                    && $exception->getMessage() === 'Unable to launch the watched server process.',
            ));
        $this->app->instance(ExceptionHandlerContract::class, $failure);
        $strategy = $this->createProbeStrategy();
        $strategy->openMode = 'fail';

        $strategy->start();
        $this->assertSame(1, $strategy->launchTokenCount());

        $strategy->start();

        $this->assertSame(2, $strategy->openCalls);
        $this->assertSame(1, $strategy->launchTokenCount());
    }

    public function testCloseFailureRestoresTheLaunchTokenAndAllowsRetry(): void
    {
        $failure = new RuntimeException('expected close failure');
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->twice()->with($failure);
        $this->app->instance(ExceptionHandlerContract::class, $handler);
        $strategy = $this->createProbeStrategy();
        $strategy->closeFailure = $failure;

        $strategy->start();
        $this->assertSame(1, $strategy->launchTokenCount());

        $strategy->start();

        $this->assertSame(2, $strategy->openCalls);
        $this->assertSame(1, $strategy->launchTokenCount());
    }

    public function testNormalServerExitRestoresTheLaunchToken(): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldNotReceive('report');
        $this->app->instance(ExceptionHandlerContract::class, $handler);
        $strategy = $this->createProbeStrategy();

        $strategy->start();

        $this->assertSame(1, $strategy->openCalls);
        $this->assertSame(1, $strategy->launchTokenCount());
    }

    private function createProbeStrategy(): ServerRestartStrategyProbe
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '/tmp/test.pid', 'daemonize' => false]],
            'watcher' => ['bin' => PHP_BINARY, 'command' => ['artisan', 'serve']],
        ]));

        return new ServerRestartStrategyProbe($this->app, new NullOutput);
    }
}

class ServerRestartStrategyProbe extends ServerRestartStrategy
{
    /** @var list<array{int, int}> */
    public array $signals = [];

    public bool $processIsRunning = true;

    public int $openCalls = 0;

    public string $openMode = 'success';

    public ?RuntimeException $closeFailure = null;

    public function useFilesystem(Filesystem $filesystem): void
    {
        $this->filesystem = $filesystem;
    }

    public function launchTokenCount(): int
    {
        return $this->channel->getLength();
    }

    protected function openProcess(array $descriptorSpec, array &$pipes): mixed
    {
        ++$this->openCalls;

        if ($this->openMode === 'fail') {
            return false;
        }

        return fopen('php://temp', 'r+');
    }

    protected function closeProcess(mixed $process): int
    {
        fclose($process);

        if ($this->closeFailure !== null) {
            throw $this->closeFailure;
        }

        return 0;
    }

    protected function signalProcess(int $pid, int $signal): bool
    {
        $this->signals[] = [$pid, $signal];

        return $signal === 0 ? $this->processIsRunning : true;
    }
}

class PidFileFilesystem extends Filesystem
{
    public function __construct(
        protected bool $pidFileExists,
        protected string $contents,
    ) {
    }

    public function exists(string $path): bool
    {
        return $this->pidFileExists;
    }

    public function get(string $path, bool $lock = false): string
    {
        return $this->contents;
    }
}
