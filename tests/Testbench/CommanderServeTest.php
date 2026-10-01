<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Concerns\Database\InteractsWithSqliteDatabaseFile;
use Hypervel\Testbench\Foundation\Process\ProcessDecorator;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Testbench\Fixtures\ServeMasterReadyServiceProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

use function Hypervel\Support\php_binary;
use function Hypervel\Testbench\package_path;
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
    public function itCanCallCommanderUsingCliAndStartServeWithoutStartupErrors(): void
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

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    public function itRestoresACustomSkeletonWhenTheTerminalInterruptsServe(): void
    {
        $filesystem = new Filesystem;
        $workingPath = ParallelTesting::tempDir('CommanderServeInterrupt');
        $skeletonPath = $workingPath . '/skeleton';
        $configurationFile = $skeletonPath . '/bootstrap/cache/testbench.yaml';
        $environmentFile = $skeletonPath . '/.env';
        $vendorLink = $skeletonPath . '/vendor';

        $filesystem->deleteDirectory($workingPath);
        $filesystem->copyDirectory(package_path('src/testbench/hypervel'), $skeletonPath);
        $filesystem->put($configurationFile, "original: true\n");
        $filesystem->link(package_path('vendor'), $workingPath . '/vendor');
        $filesystem->put(
            $workingPath . '/testbench.yaml',
            "hypervel: ./skeleton\nproviders:\n  - " . ServeMasterReadyServiceProvider::class . "\n",
        );
        $filesystem->put($workingPath . '/.env', "APP_NAME=Interrupted\n");

        $this->registerShutdownSafetyNet();
        $serverPort = $this->servePort();
        $process = new ProcessDecorator(new Process(
            [
                php_binary(),
                package_path('tests/Testbench/Fixtures/new-session.php'),
                package_path('src/testbench/bin/testbench'),
                'serve',
                '--host=127.0.0.1',
                "--port={$serverPort}",
                '--no-ansi',
            ],
            cwd: $workingPath,
            env: [
                'APP_BASE_PATH' => false,
                'APP_DEBUG' => 'true',
                'APP_ENV' => 'workbench',
                'TESTBENCH_BASE_PATH' => false,
                'TESTBENCH_WORKING_PATH' => $workingPath,
            ],
        ), 'serve');

        $process->setTimeout(30);
        $process->start();
        $pid = $process->getPid();
        static::$activeServePid = $pid;

        try {
            $this->waitForServeStartup($process, $serverPort);
            $this->waitForServeMasterReady($process);

            $this->assertSame($pid, posix_getpgid($pid));
            $this->assertTrue(is_link($vendorLink));
            $this->assertFileExists($configurationFile . '.backup');
            $this->assertSame("APP_NAME=Interrupted\n", $filesystem->get($environmentFile));

            posix_kill(-$pid, SIGINT);
            $process->wait();

            $this->assertSame(0, $process->getExitCode(), $this->combinedOutput($process));

            // The serve process changed these paths, so drop this process's cached link status.
            clearstatcache();

            $this->assertSame("original: true\n", $filesystem->get($configurationFile));
            $this->assertFileDoesNotExist($configurationFile . '.backup');
            $this->assertFileDoesNotExist($environmentFile);
            $this->assertFalse(is_link($vendorLink));
        } finally {
            // Only the serve process can lead a group with its PID, and leftover workers stay in that
            // group after the master exits, so this reaps every remaining process.
            posix_kill(-$pid, SIGKILL);
            $process->stop(0);
            static::$activeServePid = null;
            $filesystem->deleteDirectory($workingPath);
        }
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
        $process = remote("serve --host=127.0.0.1 --port={$serverPort} --no-ansi", [
            'APP_DEBUG' => 'true',
            'APP_ENV' => 'workbench',
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
     * Symfony's stop() method, which sends SIGTERM to the master only.
     * Swoole consumes SIGTERM through its native graceful shutdown, but
     * teardown must not depend on the server shutting itself down, and
     * SIGKILL cannot be caught.
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

        // posix_kill() only delivers SIGKILL; the master is reaped through Symfony
        // in stopServeProcess(). A brief grace for kernel cleanup.
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
     * Wait for the serve subprocess to begin answering HTTP requests.
     */
    private function waitForServeStartup(ProcessDecorator $process, int $serverPort): void
    {
        $deadline = microtime(true) + 10;

        do {
            if (! $process->isRunning()) {
                $this->fail("Serve process exited before answering HTTP requests on port {$serverPort}.\n{$this->combinedOutput($process)}");
            }

            if ($this->serveAnswersHttp($serverPort)) {
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        $this->fail("Serve process did not answer HTTP requests on port {$serverPort}.\n{$this->combinedOutput($process)}");
    }

    /**
     * Wait for the serve master to finish its start listeners.
     */
    private function waitForServeMasterReady(ProcessDecorator $process): void
    {
        $deadline = microtime(true) + 10;

        do {
            if (str_contains($process->getOutput(), 'serve master ready')) {
                return;
            }

            if (! $process->isRunning()) {
                $this->fail("Serve process exited before its master was ready.\n{$this->combinedOutput($process)}");
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail("Serve master was not ready in time.\n{$this->combinedOutput($process)}");
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
     * Determine whether the started server answers an HTTP request on the configured port.
     *
     * A TCP connect alone is not enough: the listening socket accepts connections before any
     * worker serves them, and under Swoole's coroutine hooks a just-released port reservation
     * keeps accepting them until the event loop runs.
     */
    private function serveAnswersHttp(int $serverPort): bool
    {
        $socket = @fsockopen('127.0.0.1', $serverPort, $errorNumber, $errorMessage, 0.2);

        if ($socket === false) {
            return false;
        }

        try {
            stream_set_timeout($socket, 0, 200_000);
            fwrite($socket, "GET / HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");

            return str_starts_with((string) fgets($socket), 'HTTP/');
        } finally {
            fclose($socket);
        }
    }

    /**
     * Reserve a free local port for the serve smoke test.
     */
    private function servePort(): int
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $port = $this->reserveServePort();

            if ($port > 10_000) {
                return $port;
            }
        }

        $this->fail('Unable to reserve a high TCP port for the serve smoke test.');
    }

    /**
     * Reserve a free local TCP port.
     */
    private function reserveServePort(): int
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
