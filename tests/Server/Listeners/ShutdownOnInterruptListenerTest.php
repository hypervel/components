<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server\Listeners;

use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use Symfony\Component\Process\Process;

use function Hypervel\Support\php_binary;

#[RequiresOperatingSystem('Linux|Darwin')]
class ShutdownOnInterruptListenerTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[DataProvider('coordinatorModes')]
    public function testShutsDownGracefullyWhenTheTerminalProcessGroupIsInterrupted(
        string $mode,
        int $workerNum,
        array $readyLines,
    ): void {
        $process = $this->startServer($mode, $workerNum, 'coroutine');
        $pid = $process->getPid();

        try {
            $this->waitForLines($process, $readyLines);
            $this->assertSame($pid, posix_getpgid($pid));

            posix_kill(-$pid, SIGINT);
            $process->wait();

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('server shutdown', $process->getOutput());
            // Worker signal handlers still receive the signal next to the coordinator's handler.
            $this->assertStringContainsString('application interrupt received', $process->getOutput());
        } finally {
            // Only the fixture can lead a group with its PID, and leftover workers stay in that
            // group after the master exits, so this reaps every remaining process.
            posix_kill(-$pid, SIGKILL);
            $process->stop(0);
        }
    }

    /**
     * Provide each process that coordinates server shutdown.
     *
     * @return array<string, array{string, int, array<string, int>}>
     */
    public static function coordinatorModes(): array
    {
        return [
            'process mode master' => ['process', 1, ['master started' => 1, 'worker started' => 1]],
            'base mode manager' => ['base', 2, ['manager started' => 1, 'worker started' => 2]],
        ];
    }

    #[DataProvider('singleProcessStartups')]
    public function testSingleProcessShutdownSharesTheWorkerSignalRegistry(string $coroutine): void
    {
        $process = $this->startServer('base', 1, $coroutine);
        $pid = $process->getPid();

        try {
            $this->waitForLines($process, ['worker started' => 1]);
            $this->assertSame($pid, posix_getpgid($pid));

            posix_kill($pid, SIGUSR2);
            $this->waitForLines($process, ['application signal received' => 1]);

            posix_kill(-$pid, SIGINT);
            $process->wait();

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('application interrupt received', $process->getOutput());
            $this->assertStringContainsString('server shutdown', $process->getOutput());
        } finally {
            posix_kill(-$pid, SIGKILL);
            $process->stop(0);
        }
    }

    /**
     * Provide single-process startups with and without coroutine support.
     *
     * @return array<string, array{string}>
     */
    public static function singleProcessStartups(): array
    {
        return [
            'coroutine startup' => ['coroutine'],
            'non-coroutine startup' => ['plain'],
        ];
    }

    /**
     * Start the fixture server in its own process group.
     */
    private function startServer(string $mode, int $workerNum, string $coroutine): Process
    {
        $process = new Process([
            php_binary(),
            dirname(__DIR__) . '/Fixtures/interrupt-server.php',
            $mode,
            (string) $workerNum,
            $coroutine,
        ]);
        $process->setTimeout(15);
        $process->start();

        return $process;
    }

    /**
     * Wait until the fixture has printed each expected line.
     *
     * @param array<string, int> $lines
     */
    private function waitForLines(Process $process, array $lines): void
    {
        $deadline = microtime(true) + 10;

        do {
            $output = $process->getOutput();
            $ready = array_filter($lines, static fn (int $count, string $line): bool => substr_count($output, $line) >= $count, ARRAY_FILTER_USE_BOTH);

            if (count($ready) === count($lines)) {
                return;
            }

            if (! $process->isRunning()) {
                $this->fail("The server exited before it was ready.\n{$output}{$process->getErrorOutput()}");
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail("The server was not ready in time.\n{$output}{$process->getErrorOutput()}");
    }
}
