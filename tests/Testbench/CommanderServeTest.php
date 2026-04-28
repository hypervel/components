<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Testbench\Concerns\Database\InteractsWithSqliteDatabaseFile;
use Hypervel\Testbench\Foundation\Process\ProcessDecorator;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\remote;

#[RequiresOperatingSystem('Linux|Darwin')]
class CommanderServeTest extends TestCase
{
    use InteractsWithSqliteDatabaseFile;

    /**
     * PID of the currently running serve master process, used by the
     * shutdown function safety net to kill leaked servers if the test
     * process dies unexpectedly (fatal error, uncaught exception, etc.).
     */
    private static ?int $activeServePid = null;

    /**
     * Whether the shutdown function has been registered for this process.
     */
    private static bool $shutdownRegistered = false;

    #[Test]
    public function itCanCallCommanderUsingCliAndStartServeWithoutStartupErrors()
    {
        $this->withoutSqliteDatabase(function (): void {
            [$process, $serverPort] = $this->startServeProcess();

            try {
                $this->assertServeStartedWithoutStartupErrors($process, $serverPort);
            } finally {
                $this->stopServeProcess($process, $serverPort);
            }
        });
    }

    #[Test]
    public function itDoesNotLeakServeRuntimeStateIntoLaterRemoteCommands(): void
    {
        $this->withoutSqliteDatabase(function (): void {
            [$process, $serverPort] = $this->startServeProcess();

            try {
                $this->assertServeStartedWithoutStartupErrors($process, $serverPort);
            } finally {
                $this->stopServeProcess($process, $serverPort);
            }

            $aboutProcess = remote('about --json');
            $aboutProcess->mustRun();

            /** @var array{environment: array{application_name: string}} $output */
            $output = json_decode($aboutProcess->getOutput(), true);

            $this->assertSame('Testbench', $output['environment']['application_name']);
        });
    }

    /**
     * Start the real serve subprocess on a disposable local port.
     *
     * @return array{0: ProcessDecorator, 1: int}
     */
    private function startServeProcess(): array
    {
        $this->registerShutdownSafetyNet();

        $serverPort = $this->servePort();
        $process = remote('serve --no-ansi', [
            'APP_DEBUG' => 'true',
            'APP_ENV' => 'workbench',
            'HTTP_SERVER_HOST' => '127.0.0.1',
            'HTTP_SERVER_PORT' => (string) $serverPort,
        ]);

        $process->setTimeout(20);
        $process->start();

        // Give Symfony a moment to spawn the process and obtain its PID.
        usleep(200_000);
        static::$activeServePid = $process->getPid();

        return [$process, $serverPort];
    }

    /**
     * Assert that the real serve subprocess starts and stays healthy.
     */
    private function assertServeStartedWithoutStartupErrors(ProcessDecorator $process, int $serverPort): void
    {
        $this->waitForServeStartup($process, $serverPort);
        $this->waitForServeStability($process);

        $output = $this->combinedOutput($process);

        $this->assertTrue($process->isRunning(), "Serve process exited after startup.\n{$output}");
        $this->assertStringNotContainsString('The event-loop has already been created', $output);
        $this->assertStringNotContainsString('document_root', $output);
        $this->assertStringNotContainsString('TypeError', $output);
        $this->assertStringNotContainsString('ReloadDotenvAndConfig', $output);
        $this->assertStringNotContainsString('Cannot assign Hypervel\Support\Facades\Config', $output);
    }

    /**
     * Stop the serve subprocess and verify the entire process tree is dead.
     *
     * Kills the process tree directly with SIGKILL rather than using
     * Symfony's stop() method, which always sends SIGTERM first and waits
     * the full timeout before escalating. Swoole blocks SIGTERM in the
     * master process, so SIGTERM never triggers a shutdown — the timeout
     * just wastes seconds. SIGKILL is immediate and cannot be blocked.
     *
     * Descendants are collected before killing the master because once
     * the master dies, its children are re-parented to PID 1 and can
     * no longer be found by walking PPIDs.
     */
    private function stopServeProcess(ProcessDecorator $process, int $serverPort): void
    {
        $pid = $process->getPid();

        // Collect the full descendant tree BEFORE killing the master.
        $descendants = $pid !== null ? static::collectDescendants($pid) : [];

        // Kill leaves first, then the master. Direct SIGKILL avoids
        // Symfony's SIGTERM-then-wait-then-escalate dance.
        foreach (array_reverse($descendants) as $descendantPid) {
            if (posix_kill($descendantPid, 0)) {
                posix_kill($descendantPid, SIGKILL);
            }
        }

        if ($pid !== null && posix_kill($pid, 0)) {
            posix_kill($pid, SIGKILL);
        }

        // Let Symfony know the process is gone so it cleans up handles.
        if ($process->isRunning()) {
            $process->stop(0);
        }

        static::$activeServePid = null;

        // Verify the master PID is dead. This turns this test into a
        // regression detector for the leak itself.
        $this->assertServeFullyStopped($pid);
    }

    /**
     * Register a process-level shutdown function to kill leaked serve processes.
     *
     * Covers fatal errors, uncaught exceptions, and exit() — scenarios where
     * the test's finally block never runs. Only registered once per process.
     */
    private function registerShutdownSafetyNet(): void
    {
        if (static::$shutdownRegistered) {
            return;
        }

        static::$shutdownRegistered = true;

        register_shutdown_function(static function (): void {
            $pid = static::$activeServePid;

            if ($pid === null) {
                return;
            }

            // Collect descendants before killing the master so we can
            // find them by PPID before they get re-parented to PID 1.
            $descendants = posix_kill($pid, 0)
                ? static::collectDescendants($pid)
                : [];

            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
            }

            foreach (array_reverse($descendants) as $descendantPid) {
                if (posix_kill($descendantPid, 0)) {
                    posix_kill($descendantPid, SIGKILL);
                }
            }

            static::$activeServePid = null;
        });
    }

    /**
     * Assert that the serve master process is dead after teardown.
     */
    private function assertServeFullyStopped(?int $pid): void
    {
        if ($pid === null) {
            return;
        }

        // SIGKILL is synchronous — the process is dead by the time
        // posix_kill(SIGKILL) returns. A brief grace for kernel cleanup.
        usleep(50_000);

        if (posix_kill($pid, 0)) {
            $this->fail("Serve master PID {$pid} is still alive after teardown.");
        }
    }

    /**
     * Collect all descendant PIDs of the given PID in depth-first order.
     *
     * Scans /proc once to build a PID→children map, then walks the subtree.
     * Returns PIDs in parent-before-children order so that callers can
     * reverse the list to kill leaves first (or use as-is to kill top-down).
     *
     * @return array<int, int>
     */
    private static function collectDescendants(int $rootPid): array
    {
        $childrenMap = static::buildChildrenMap();
        $descendants = [];

        $stack = $childrenMap[$rootPid] ?? [];

        while ($stack !== []) {
            $pid = array_pop($stack);
            $descendants[] = $pid;

            foreach ($childrenMap[$pid] ?? [] as $childPid) {
                $stack[] = $childPid;
            }
        }

        return $descendants;
    }

    /**
     * Build a map of PID → direct child PIDs by scanning /proc once.
     *
     * @return array<int, array<int, int>>
     */
    private static function buildChildrenMap(): array
    {
        $map = [];

        if (is_dir('/proc')) {
            foreach (scandir('/proc') as $entry) {
                if (! ctype_digit($entry)) {
                    continue;
                }

                $statusFile = "/proc/{$entry}/status";
                if (! is_readable($statusFile)) {
                    continue;
                }

                $contents = @file_get_contents($statusFile);
                if ($contents === false) {
                    continue;
                }

                if (preg_match('/^PPid:\s+(\d+)$/m', $contents, $matches)) {
                    $map[(int) $matches[1]][] = (int) $entry;
                }
            }

            return $map;
        }

        // Fallback for macOS: use ps to get all PID/PPID pairs.
        $output = [];
        exec('ps -eo pid=,ppid= 2>/dev/null', $output);

        foreach ($output as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) === 2) {
                $map[(int) $parts[1]][] = (int) $parts[0];
            }
        }

        return $map;
    }

    /**
     * Wait for the serve subprocess to begin accepting connections.
     */
    private function waitForServeStartup(ProcessDecorator $process, int $serverPort): void
    {
        $deadline = microtime(true) + 10;

        do {
            if (! $process->isRunning()) {
                $this->fail("Serve process exited before accepting connections.\n{$this->combinedOutput($process)}");
            }

            if ($this->canConnectToServePort($serverPort)) {
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        $this->fail("Serve process did not accept connections.\n{$this->combinedOutput($process)}");
    }

    /**
     * Give worker startup a moment to finish and fail if the process crashes.
     */
    private function waitForServeStability(ProcessDecorator $process): void
    {
        usleep(750_000);

        if (! $process->isRunning()) {
            $this->fail("Serve process crashed shortly after startup.\n{$this->combinedOutput($process)}");
        }
    }

    /**
     * Determine whether the started server is listening on the configured port.
     */
    private function canConnectToServePort(int $serverPort): bool
    {
        $socket = @fsockopen('127.0.0.1', $serverPort, $errorNumber, $errorMessage, 0.2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Reserve a free local port for the serve smoke test.
     */
    private function servePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

        if ($socket === false) {
            $this->fail("Unable to reserve a free TCP port for the serve smoke test: {$errorMessage} ({$errorNumber}).");
        }

        $address = stream_socket_get_name($socket, false);

        fclose($socket);

        if (! is_string($address)) {
            $this->fail('Unable to determine the reserved TCP port for the serve smoke test.');
        }

        $port = (int) substr($address, strrpos($address, ':') + 1);

        if ($port <= 0) {
            $this->fail("Unable to parse a valid TCP port from [{$address}].");
        }

        return $port;
    }

    /**
     * Get the current stdout and stderr for the serve subprocess.
     */
    private function combinedOutput(ProcessDecorator $process): string
    {
        return $process->getOutput() . $process->getErrorOutput();
    }
}
