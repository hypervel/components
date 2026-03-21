<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Testbench\Concerns\Database\InteractsWithSqliteDatabaseFile;
use Hypervel\Testbench\Foundation\Process\ProcessDecorator;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\remote;

/**
 * @internal
 * @coversNothing
 */
#[RequiresOperatingSystem('Linux|Darwin')]
class CommanderServeTest extends TestCase
{
    use InteractsWithSqliteDatabaseFile;

    #[Test]
    public function itCanCallCommanderUsingCliAndStartServeWithoutStartupErrors()
    {
        $this->withoutSqliteDatabase(function (): void {
            [$process, $serverPort] = $this->startServeProcess();

            try {
                $this->assertServeStartedWithoutStartupErrors($process, $serverPort);
            } finally {
                $this->stopServeProcess($process);
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
                $this->stopServeProcess($process);
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
        $serverPort = $this->servePort();
        $process = remote('serve --no-ansi', [
            'APP_DEBUG' => 'true',
            'APP_ENV' => 'workbench',
            'HTTP_SERVER_HOST' => '127.0.0.1',
            'HTTP_SERVER_PORT' => (string) $serverPort,
        ]);

        $process->setTimeout(20);
        $process->start();

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
     * Stop the real serve subprocess if it is still running.
     */
    private function stopServeProcess(ProcessDecorator $process): void
    {
        if ($process->isRunning()) {
            $process->stop(3, SIGTERM);
        }
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
