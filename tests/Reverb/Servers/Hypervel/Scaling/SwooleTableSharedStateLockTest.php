<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Swoole\Process;
use Swoole\Table;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\run;

class SwooleTableSharedStateLockTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testContendedStripeBacksOffAndAcquiresAfterRelease(): void
    {
        $state = $this->createState(LockTestSharedState::class);
        $state->holdLockFor('key');
        $called = false;

        run(function () use ($state, &$called): void {
            go(function () use ($state): void {
                usleep(5_000);
                $state->releaseLockFor('key');
            });

            $state->withLockFor('key', function () use (&$called): void {
                $called = true;
            });
        });

        $this->assertTrue($called);
    }

    public function testAbandonedStripeFailsWithinTheAcquisitionDeadline(): void
    {
        $state = $this->createState(LockTestSharedState::class);
        $process = new Process(function (Process $process) use ($state): void {
            try {
                $state->holdLockFor('key');

                try {
                    $state->withLockFor('key', fn (): bool => true);
                    $message = 'lock unexpectedly acquired';
                } catch (RuntimeException $exception) {
                    $message = $exception->getMessage();
                }

                $process->write($message);
            } finally {
                // Never run PHPUnit/Testbench shutdown handlers inherited from the parent.
                posix_kill(getmypid(), SIGKILL);
            }
        }, false, SOCK_STREAM);

        $pid = $process->start();

        if ($pid === false) {
            $this->fail('Unable to start Reverb lock child.');
        }

        $process->setBlocking(false);
        $message = '';
        $reaped = false;
        $deadline = hrtime(true) + 250_000_000;

        try {
            while ($message === '' && hrtime(true) < $deadline) {
                $chunk = $process->read();

                if (is_string($chunk) && $chunk !== '') {
                    $message .= $chunk;
                    break;
                }

                if ($this->reapIfExited($pid)) {
                    $reaped = true;
                    break;
                }

                usleep(1_000);
            }
        } finally {
            if (! $reaped && Process::kill($pid, 0)) {
                Process::kill($pid, SIGKILL);
            }

            $process->close();

            if (! $reaped) {
                $reapDeadline = hrtime(true) + 1_000_000_000;

                while (! $this->reapIfExited($pid)) {
                    if (hrtime(true) >= $reapDeadline) {
                        throw new RuntimeException("Timed out reaping Reverb lock child [{$pid}].");
                    }

                    usleep(1_000);
                }
            }
        }

        $this->assertSame(
            'Timed out acquiring a Swoole table shared-state lock.',
            $message,
        );
    }

    public function testFailedLockRowsAreReportedOnlyAfterTheirStripeIsReleased(): void
    {
        $state = $this->createState(ReportingProbeSharedState::class);

        $this->assertFalse($state->tryCacheMissLock('app', 'channel'));
        $state->setSmoothingPending('app', 'channel', 1_000);
        $state->setMemberSmoothingPending('app', 'channel', 'user', 1_000);

        $this->assertSame([
            'cache-miss-lock:app:channel',
            'smoothing:app:channel',
            'smoothing:app:channel:user',
        ], $state->reportedKeys);
        $this->assertFalse($state->reportedWhileLocked);
    }

    /**
     * Reap the owned child if it has exited.
     */
    private function reapIfExited(int $pid): bool
    {
        $status = 0;
        $result = pcntl_waitpid($pid, $status, WNOHANG);

        if ($result === $pid) {
            return true;
        }

        if ($result !== -1) {
            return false;
        }

        $error = pcntl_get_last_error();

        if ($error === PCNTL_ECHILD) {
            return true;
        }

        if ($error === PCNTL_EINTR) {
            return false;
        }

        throw new RuntimeException(
            "Unable to reap Reverb lock child [{$pid}]: " . pcntl_strerror($error),
        );
    }

    /**
     * Create a shared-state test double with real Swoole tables.
     *
     * @template T of SwooleTableSharedState
     * @param class-string<T> $class
     * @return T
     */
    private function createState(string $class): SwooleTableSharedState
    {
        $table = new Table(128);
        $table->column('count', Table::TYPE_INT);
        $table->create();

        $lockTable = new Table(128);
        $lockTable->column('locked_at', Table::TYPE_FLOAT);
        $lockTable->create();

        return new $class($table, $lockTable);
    }
}

class LockTestSharedState extends SwooleTableSharedState
{
    protected const LOCK_ACQUIRE_TIMEOUT_NANOSECONDS = 50_000_000;

    public function holdLockFor(string $key): void
    {
        $this->lockFor($key)->set(1);
    }

    public function releaseLockFor(string $key): void
    {
        $this->lockFor($key)->set(0);
    }

    public function withLockFor(string $key, callable $callback): mixed
    {
        $lock = $this->lockFor($key);
        $this->acquire($lock);

        try {
            return $callback();
        } finally {
            $this->release($lock);
        }
    }
}

class ReportingProbeSharedState extends SwooleTableSharedState
{
    /** @var list<string> */
    public array $reportedKeys = [];

    public bool $reportedWhileLocked = false;

    protected function setLockRow(string $key, float $timestamp): bool
    {
        return false;
    }

    protected function reportFullLockTable(string $key): void
    {
        $this->reportedKeys[] = $key;
        $this->reportedWhileLocked = $this->reportedWhileLocked
            || $this->lockFor($key)->get() !== 0;
    }
}
