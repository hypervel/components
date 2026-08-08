<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Support\Logger;
use Hypervel\Prompts\Task;
use Hypervel\Prompts\Themes\Default\TaskRenderer;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use RuntimeException;

class TaskProcessTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected function setUp(): void
    {
        parent::setUp();

        Prompt::fake();
        Prompt::addTheme('task-process-test', [
            TaskProcessFixture::class => TaskRenderer::class,
            TaskSocketFailureFixture::class => TaskRenderer::class,
            TaskForkFailureFixture::class => TaskRenderer::class,
            TaskChildFailureFixture::class => TaskRenderer::class,
            TaskSettlementTimeoutFixture::class => TaskRenderer::class,
            TaskTransportFailureFixture::class => TaskRenderer::class,
            TaskInterruptedWaitFixture::class => TaskRenderer::class,
        ]);
        Prompt::theme('task-process-test');
    }

    #[RunInSeparateProcess]
    public function testSuccessfulRendererRestoresSignalAndTerminalStateAndReapsChild(): void
    {
        $previousHandler = static function (): void {
        };

        pcntl_async_signals(false);
        pcntl_signal(SIGINT, $previousHandler);

        $task = new TaskProcessFixture(label: 'Running');
        $result = $task->run(function (Logger $logger): string {
            $logger->line('working');

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertNotNull($task->forkedPid);
        $this->assertSame(-1, pcntl_waitpid($task->forkedPid, $status, WNOHANG));
        $this->assertSame($previousHandler, pcntl_signal_get_handler(SIGINT));
        $this->assertFalse(pcntl_async_signals());
        $this->assertFalse(
            (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
        );
    }

    #[RunInSeparateProcess]
    public function testInterruptedWaitRetriesUntilTheRendererIsReaped(): void
    {
        $alarmHandled = false;

        pcntl_signal(SIGALRM, static function () use (&$alarmHandled): void {
            $alarmHandled = true;
        });

        $task = new TaskInterruptedWaitFixture(label: 'Running');

        try {
            $result = $task->run(function (Logger $logger): string {
                pcntl_alarm(1);

                return 'done';
            });
        } finally {
            pcntl_alarm(0);
        }

        $this->assertSame('done', $result);
        $this->assertTrue($alarmHandled);
        $this->assertNotNull($task->forkedPid);
        $this->assertSame(-1, pcntl_waitpid($task->forkedPid, $status, WNOHANG));
    }

    #[RunInSeparateProcess]
    public function testKernelAutoReapingDoesNotFailACleanRenderer(): void
    {
        pcntl_signal(SIGCHLD, SIG_IGN);

        $task = new TaskProcessFixture(label: 'Running');
        $result = $task->run(fn (Logger $logger): string => 'done');

        $this->assertSame('done', $result);
        $this->assertNotNull($task->forkedPid);
        $this->assertSame(-1, pcntl_waitpid($task->forkedPid, $status, WNOHANG));
        $this->assertSame(PCNTL_ECHILD, pcntl_get_last_error());
    }

    #[RunInSeparateProcess]
    public function testKernelAutoReapingStillReportsRendererFailure(): void
    {
        pcntl_signal(SIGCHLD, SIG_IGN);

        $callbackRan = false;
        $task = new TaskChildFailureFixture(label: 'Running');

        try {
            $task->run(function (Logger $logger) use (&$callbackRan): void {
                $callbackRan = true;
            });

            $this->fail('Expected the child renderer to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The prompt renderer process failed.', $exception->getMessage());
        }

        $this->assertTrue($callbackRan);
        $this->assertNotNull($task->forkedPid);
        $this->assertSame(-1, pcntl_waitpid($task->forkedPid, $status, WNOHANG));
        $this->assertSame(PCNTL_ECHILD, pcntl_get_last_error());
    }

    #[RunInSeparateProcess]
    public function testSocketPairFailureFallsBackBeforeRunningCallback(): void
    {
        $callbackRuns = 0;
        $task = new TaskSocketFailureFixture(label: 'Running');

        $result = $task->run(function (Logger $logger) use (&$callbackRuns): string {
            ++$callbackRuns;

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(1, $callbackRuns);
        $this->assertTrue($task->static);
    }

    #[RunInSeparateProcess]
    public function testForkFailureRestoresStateAndFallsBackBeforeRunningCallback(): void
    {
        $previousHandler = static function (): void {
        };
        $callbackRuns = 0;

        pcntl_async_signals(false);
        pcntl_signal(SIGINT, $previousHandler);

        $task = new TaskForkFailureFixture(label: 'Running');
        $result = $task->run(function (Logger $logger) use (&$callbackRuns): string {
            ++$callbackRuns;

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(1, $callbackRuns);
        $this->assertTrue($task->static);
        $this->assertNull($task->forkedPid);
        $this->assertSame($previousHandler, pcntl_signal_get_handler(SIGINT));
        $this->assertFalse(pcntl_async_signals());
        $this->assertFalse(
            (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
        );
    }

    #[RunInSeparateProcess]
    public function testCallbackFailureRemainsPrimaryAndChildIsReaped(): void
    {
        $task = new TaskChildFailureFixture(label: 'Running');

        try {
            $task->run(function (Logger $logger): never {
                throw new RuntimeException('callback failed');
            });

            $this->fail('Expected the task callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('callback failed', $exception->getMessage());
        }

        $this->assertNotNull($task->forkedPid);
        $this->assertSame(-1, pcntl_waitpid($task->forkedPid, $status, WNOHANG));
    }

    #[RunInSeparateProcess]
    public function testCallbackFailureRemainsPrimaryWhenTerminalRestorationFails(): void
    {
        Prompt::terminal()
            ->shouldReceive('restoreTty') // @phpstan-ignore-line
            ->once()
            ->andThrow(new RuntimeException('terminal restoration failed'));

        $task = new TaskProcessFixture(label: 'Running');

        try {
            $task->run(function (Logger $logger): never {
                throw new RuntimeException('callback failed');
            });

            $this->fail('Expected the task callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('callback failed', $exception->getMessage());
        }

        $this->assertNotNull($task->forkedPid);
        $this->assertSame(-1, pcntl_waitpid($task->forkedPid, $status, WNOHANG));
    }

    #[RunInSeparateProcess]
    public function testChildRendererFailureSurfacesAfterSuccessfulCallbackAndIsReaped(): void
    {
        $callbackRan = false;
        $task = new TaskChildFailureFixture(label: 'Running');

        try {
            $task->run(function (Logger $logger) use (&$callbackRan): void {
                $callbackRan = true;
            });

            $this->fail('Expected the child renderer to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The prompt renderer process failed.', $exception->getMessage());
        }

        $this->assertTrue($callbackRan);
        $this->assertNotNull($task->forkedPid);
        $this->assertSame(-1, pcntl_waitpid($task->forkedPid, $status, WNOHANG));
    }

    #[RunInSeparateProcess]
    public function testSettlementTimeoutTerminatesAndReapsChild(): void
    {
        $task = new TaskSettlementTimeoutFixture(label: 'Running');
        $startedAt = microtime(true);

        try {
            $task->run(fn (Logger $logger): null => null);

            $this->fail('Expected renderer settlement to time out.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The prompt renderer timed out while settling.', $exception->getMessage());
        }

        $this->assertLessThan(2.0, microtime(true) - $startedAt);
        $this->assertNotNull($task->forkedPid);
        $this->assertSame(-1, pcntl_waitpid($task->forkedPid, $status, WNOHANG));
    }

    #[RunInSeparateProcess]
    public function testLoggerTransportFailureIsReportedAfterCallbackAndChildIsReaped(): void
    {
        $transportFailure = null;
        $callbackCompleted = false;
        $task = new TaskTransportFailureFixture(label: 'Running');

        try {
            $task->run(function (Logger $logger) use (&$callbackCompleted, &$transportFailure): void {
                $logger->line(str_repeat('x', 4 * 1024 * 1024));
                $transportFailure = $logger->transportFailure();
                $callbackCompleted = true;
            });

            $this->fail('Expected the Logger transport to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($transportFailure, $exception);
        }

        $this->assertTrue($callbackCompleted);
        $this->assertInstanceOf(RuntimeException::class, $transportFailure);
        $this->assertNotNull($task->forkedPid);
        $this->assertSame(-1, pcntl_waitpid($task->forkedPid, $status, WNOHANG));
    }

    #[RunInSeparateProcess]
    public function testResetWriteFailureIsReportedAndChildIsReaped(): void
    {
        $loggerFailure = null;
        $task = new TaskTransportFailureFixture(label: 'Running');

        try {
            $task->run(function (Logger $logger) use ($task, &$loggerFailure): void {
                $task->awaitRendererCloseForTest();
                $loggerFailure = $logger->transportFailure();
            });

            $this->fail('Expected the reset write to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The prompt renderer closed while receiving output.', $exception->getMessage());
        }

        $this->assertNull($loggerFailure);
        $this->assertNotNull($task->forkedPid);
        $this->assertSame(-1, pcntl_waitpid($task->forkedPid, $status, WNOHANG));
    }

    #[RunInSeparateProcess]
    public function testDestructorSettlesAnInterruptedOwnedRenderer(): void
    {
        $previousHandler = static function (): void {
        };
        pcntl_async_signals(false);
        pcntl_signal(SIGINT, $previousHandler);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();

        if ($pid === 0) {
            fclose($sockets[1]);
            sleep(10);
            fclose($sockets[0]);
            exit;
        }

        fclose($sockets[0]);
        $task = new TaskProcessFixture(label: 'Running');
        $task->adoptRendererForTest($pid, $sockets[1]);

        unset($task);

        $this->assertSame(-1, pcntl_waitpid($pid, $status, WNOHANG));
        $this->assertSame($previousHandler, pcntl_signal_get_handler(SIGINT));
        $this->assertFalse(pcntl_async_signals());
        $this->assertFalse(
            (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
        );
    }
}

class TaskProcessFixture extends Task
{
    public ?int $forkedPid = null;

    /**
     * Fork the process renderer.
     */
    protected function forkProcess(): int
    {
        $pid = parent::forkProcess();

        if ($pid > 0) {
            $this->forkedPid = $pid;
        }

        return $pid;
    }

    /**
     * Adopt an interrupted renderer operation for destructor coverage.
     *
     * @param resource $socket
     */
    public function adoptRendererForTest(int $pid, $socket): void
    {
        $this->pid = $pid;
        $this->socket = $socket;
        $this->captureSignalState();
        $this->hideCursor();
    }
}

class TaskSocketFailureFixture extends Task
{
    /**
     * Create the process renderer socket pair.
     */
    protected function createSocketPair(): false
    {
        return false;
    }
}

class TaskForkFailureFixture extends TaskProcessFixture
{
    /**
     * Fork the process renderer.
     */
    protected function forkProcess(): int
    {
        return -1;
    }
}

class TaskChildFailureFixture extends TaskProcessFixture
{
    /**
     * Run the child process renderer.
     *
     * @param resource $socket
     */
    protected function runRendererProcess($socket): never
    {
        stream_set_blocking($socket, true);
        fgets($socket);
        fclose($socket);

        exit(1);
    }
}

class TaskSettlementTimeoutFixture extends TaskProcessFixture
{
    /**
     * Run the child process renderer.
     *
     * @param resource $socket
     */
    protected function runRendererProcess($socket): never
    {
        sleep(10);
        fclose($socket);

        exit;
    }
}

class TaskTransportFailureFixture extends TaskProcessFixture
{
    /**
     * Wait until the renderer closes the parent endpoint.
     */
    public function awaitRendererCloseForTest(): void
    {
        while (! feof($this->socket)) {
            fread($this->socket, 1);
        }
    }

    /**
     * Run the child process renderer.
     *
     * @param resource $socket
     */
    protected function runRendererProcess($socket): never
    {
        fclose($socket);

        exit;
    }
}

class TaskInterruptedWaitFixture extends TaskProcessFixture
{
    /**
     * Exit successfully without acknowledging settlement.
     *
     * This proves an available exit status remains authoritative.
     *
     * @param resource $socket
     */
    protected function runRendererProcess($socket): never
    {
        stream_set_blocking($socket, true);
        fgets($socket);
        fclose($socket);
        sleep(2);

        exit;
    }
}
