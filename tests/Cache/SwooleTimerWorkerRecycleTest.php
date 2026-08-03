<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use Swoole\Coroutine\Http\Client;
use Symfony\Component\Process\Process;

#[RequiresOperatingSystem('Linux|Darwin')]
class SwooleTimerWorkerRecycleTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('SwooleTimerWorkerRecycleTest');
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testOwnedCacheTimersDoNotDelayWorkerRecycle(): void
    {
        $port = $this->reservePort();
        $statePath = $this->tempDir . '/state.log';
        $logPath = $this->tempDir . '/swoole.log';
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/Fixtures/SwooleTimerRecycleServer.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            (string) $port,
            $statePath,
            $logPath,
        ]);
        $process->start();

        try {
            $this->waitForState(
                $process,
                $statePath,
                static fn (string $state): bool => substr_count($state, "start\n") >= 1,
            );

            $client = new Client('127.0.0.1', $port);
            $this->assertTrue($client->get('/'));
            $this->assertSame('ok', $client->body);
            $client->close();

            $this->waitForState(
                $process,
                $statePath,
                static fn (string $state): bool => str_contains($state, "exit\n")
                    && substr_count($state, "start\n") >= 2,
            );
        } finally {
            $process->stop(2);
        }

        $output = strtolower(
            $process->getOutput()
            . $process->getErrorOutput()
            . (is_file($logPath) ? file_get_contents($logPath) : '')
        );

        $this->assertStringNotContainsString('worker exit timeout', $output);
        $this->assertStringNotContainsString('forced to terminate', $output);
    }

    private function waitForState(Process $process, string $statePath, callable $condition): void
    {
        $deadline = microtime(true) + 5;

        do {
            $state = is_file($statePath) ? file_get_contents($statePath) : '';

            if (is_string($state) && $condition($state)) {
                return;
            }

            if (! $process->isRunning()) {
                $this->fail('The Swoole timer recycle server exited early: ' . $process->getErrorOutput());
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        $this->fail('Timed out waiting for the Swoole timer recycle state.');
    }

    private function reservePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

        if ($socket === false) {
            $this->fail("Unable to reserve a local TCP port: {$errorMessage} ({$errorNumber}).");
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        if (! is_string($address)) {
            $this->fail('Unable to determine the reserved TCP port.');
        }

        return (int) substr($address, strrpos($address, ':') + 1);
    }
}
