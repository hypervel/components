<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Server\Exceptions\InvalidArgumentException;
use Hypervel\Server\Exceptions\ServerException;
use Hypervel\Server\ServerReloader;
use Hypervel\Tests\TestCase;
use InvalidArgumentException as BaseInvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Constant;

class ServerReloaderTest extends TestCase
{
    public function testMissingPidFileConfigurationFailsBeforeReadingTheFilesystem(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldNotReceive('get');

        $this->expectException(BaseInvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Configuration value for key [server.settings.pid_file] must be a string, NULL given.'
        );

        $this->reloader([], $filesystem)->reload();
    }

    public function testUnreadablePidFileExceptionIsNotWrapped(): void
    {
        $exception = new FileNotFoundException('File does not exist.');
        $filesystem = m::mock(Filesystem::class);
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->andThrow($exception);

        try {
            $this->reloader($this->settings(), $filesystem)->reload();
            $this->fail('Expected the PID file exception to be thrown.');
        } catch (FileNotFoundException $thrown) {
            $this->assertSame($exception, $thrown);
        }
    }

    #[DataProvider('invalidProcessIds')]
    public function testInvalidProcessIdsAreRejected(string $contents): void
    {
        $filesystem = m::mock(Filesystem::class);
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->andReturn($contents);
        $reloader = $this->reloader($this->settings(), $filesystem);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The server PID file [/tmp/hypervel.pid] does not contain a valid process ID.'
        );

        $reloader->reload();
    }

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

    public function testReloadSignalsEventWorkers(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->andReturn("123\n");
        $reloader = $this->reloader($this->settings(), $filesystem);
        $reloader->returnSignalResults(true);

        $reloader->reload();

        $this->assertSame([[123, SIGUSR1]], $reloader->signals);
    }

    public function testReloadSignalsEventAndTaskWorkers(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->andReturn('123');
        $reloader = $this->reloader($this->settings(taskWorkers: 2), $filesystem);
        $reloader->returnSignalResults(true, true);

        $reloader->reload();

        $this->assertSame([[123, SIGUSR1], [123, SIGUSR2]], $reloader->signals);
    }

    public function testEventWorkerSignalFailureIsReported(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->andReturn('123');
        $reloader = $this->reloader($this->settings(taskWorkers: 2), $filesystem);
        $reloader->returnSignalResults(false);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Unable to send [SIGUSR1] to reload event workers.');

        $reloader->reload();
    }

    public function testTaskWorkerSignalFailureIsReported(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $filesystem->expects('get')->with('/tmp/hypervel.pid')->andReturn('123');
        $reloader = $this->reloader($this->settings(taskWorkers: 2), $filesystem);
        $reloader->returnSignalResults(true, false);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Unable to send [SIGUSR2] to reload task workers.');

        $reloader->reload();
    }

    private function reloader(array $config, Filesystem $filesystem): ServerReloaderTestReloader
    {
        return new ServerReloaderTestReloader(new Repository($config), $filesystem);
    }

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

class ServerReloaderTestReloader extends ServerReloader
{
    /** @var list<array{int, int}> */
    public array $signals = [];

    /** @var list<bool> */
    private array $signalResults = [];

    public function returnSignalResults(bool ...$results): void
    {
        $this->signalResults = $results;
    }

    protected function signalProcess(int $pid, int $signal): bool
    {
        $this->signals[] = [$pid, $signal];

        return array_shift($this->signalResults) ?? true;
    }
}
