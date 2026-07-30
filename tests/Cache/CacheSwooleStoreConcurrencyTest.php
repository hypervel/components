<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\SwooleTableManager;
use Hypervel\Cache\SwooleTableState;
use Hypervel\Contracts\Container\Container;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;
use RuntimeException;
use Swoole\Atomic;
use Swoole\Process;
use Throwable;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\run;

class CacheSwooleStoreConcurrencyTest extends TestCase
{
    private const FRAME_HEADER_BYTES = 4;

    private const MAX_FRAME_BYTES = 1_048_576;

    protected bool $runTestsInCoroutine = false;

    public function testConcurrentAddHasExactlyOneWinner(): void
    {
        $state = $this->createState();

        $results = $this->runConcurrentProcesses($state, 16, function (int $id, SwooleStore $store): array {
            return [
                'id' => $id,
                'won' => $store->add('key', $id, 60),
            ];
        });

        $winners = array_values(array_filter($results, fn (array $result): bool => $result['won']));

        $this->assertCount(1, $winners);
        $this->assertSame($winners[0]['id'], $this->createStore($state)->get('key'));
    }

    public function testConcurrentAddForExpiredPhysicalRowHasExactlyOneWinner(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $state->table()->set($this->tableKey($store, 'userKey', 'key'), [
            'value' => serialize('expired'),
            'expiration' => time() - 100,
        ]);

        $results = $this->runConcurrentProcesses($state, 16, function (int $id, SwooleStore $store): array {
            return [
                'id' => $id,
                'won' => $store->add('key', $id, 60),
            ];
        });

        $winners = array_values(array_filter($results, fn (array $result): bool => $result['won']));

        $this->assertCount(1, $winners);
        $this->assertSame($winners[0]['id'], $store->get('key'));
    }

    public function testConcurrentIncrementDoesNotLoseUpdates(): void
    {
        $state = $this->createState();
        $processes = 8;
        $incrementsPerProcess = 100;

        $this->runConcurrentProcesses(
            $state,
            $processes,
            function (int $id, SwooleStore $store) use ($incrementsPerProcess): bool {
                for ($i = 0; $i < $incrementsPerProcess; ++$i) {
                    $store->increment('counter');
                }

                return true;
            }
        );

        $this->assertSame($processes * $incrementsPerProcess, $this->createStore($state)->get('counter'));
    }

    public function testConcurrentLockAcquireHasExactlyOneWinner(): void
    {
        $state = $this->createState();

        $results = $this->runConcurrentProcesses($state, 16, function (int $id, SwooleStore $store): array {
            return [
                'id' => $id,
                'won' => $store->lock('lock', 60, "owner-{$id}")->acquire(),
            ];
        });

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['won']));
    }

    public function testContendedStateLockBacksOffAndAcquiresAfterRelease(): void
    {
        $state = $this->createLockState();
        $state->holdLockFor('key');
        $called = false;

        run(function () use ($state, &$called): void {
            go(function () use ($state): void {
                usleep(5_000);
                $state->releaseLockFor('key');
            });

            $state->withRowLock('key', function () use (&$called): void {
                $called = true;
            });
        });

        $this->assertTrue($called);
    }

    public function testStateLockFailureIsBoundedAndDescriptive(): void
    {
        $state = $this->createLockState();

        try {
            $this->runConcurrentProcesses(
                $state,
                1,
                function (int $id, SwooleStore $store, LockTestSwooleTableState $state): bool {
                    $state->holdLockFor('key');
                    $state->withRowLock('key', fn (): bool => true);

                    return true;
                },
                timeout: 0.25,
            );

            $this->fail('The pre-locked stripe should time out.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Timed out acquiring a Swoole table state lock.',
                $exception->getMessage(),
            );
        }
    }

    public function testAllStripeFailureReleasesEarlierAcquisitions(): void
    {
        $state = new FailingAllStripeSwooleTableState(
            $this->createState()->table(),
            12345,
        );

        try {
            $state->withAllRowLocks(fn (): bool => true);
            $this->fail('The third stripe acquisition should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic stripe acquisition failure.', $exception->getMessage());
        }

        $this->assertTrue($state->firstAcquiredStripesAreReleased());
    }

    public function testChildExitBeforeReadyFailsWithinTheHarnessDeadline(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exited before reaching the start barrier');

        $this->runConcurrentProcesses(
            $this->createState(),
            1,
            fn (): bool => true,
            beforeReady: fn () => posix_kill(getmypid(), SIGKILL),
            timeout: 0.25,
        );
    }

    public function testChildExitBeforePayloadFailsWithinTheHarnessDeadline(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exited before sending a complete payload');

        $this->runConcurrentProcesses(
            $this->createState(),
            1,
            fn () => posix_kill(getmypid(), SIGKILL),
            timeout: 0.25,
        );
    }

    public function testChildThrowableIsReturnedAsAnErrorPayload(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Child process failed: expected child failure');

        $this->runConcurrentProcesses(
            $this->createState(),
            1,
            fn () => throw new RuntimeException('expected child failure'),
        );
    }

    public function testStalledChildFailsWithinTheHarnessDeadline(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Timed out waiting for cache concurrency child');

        $this->runConcurrentProcesses(
            $this->createState(),
            1,
            function (): bool {
                usleep(1_000_000);

                return true;
            },
            timeout: 0.05,
        );
    }

    /**
     * Run callbacks in forked processes sharing one pre-created Swoole table state.
     */
    private function runConcurrentProcesses(
        SwooleTableState $state,
        int $count,
        callable $callback,
        ?callable $beforeReady = null,
        float $timeout = 5.0,
    ): array {
        $ready = new Atomic(0);
        $start = new Atomic(0);
        $processes = [];
        $pids = [];
        $reaped = [];

        for ($i = 0; $i < $count; ++$i) {
            $processes[] = new Process(function (Process $process) use (
                $state,
                $ready,
                $start,
                $callback,
                $beforeReady,
                $i,
            ): void {
                try {
                    $store = $this->createStore($state);

                    $beforeReady?->__invoke($i, $store, $state);
                    $ready->add(1);

                    while ($start->get() === 0) {
                        usleep(100);
                    }

                    try {
                        $payload = [
                            'ok' => true,
                            'result' => $callback($i, $store, $state),
                        ];
                    } catch (Throwable $exception) {
                        $payload = [
                            'ok' => false,
                            'error' => $exception->getMessage(),
                        ];
                    }

                    $this->writeChildPayload($process, $payload);
                } finally {
                    // Never run PHPUnit/Testbench shutdown handlers inherited from the parent.
                    posix_kill(getmypid(), SIGKILL);
                }
            }, false, SOCK_STREAM);
        }

        $results = [];
        $exception = null;
        $deadline = hrtime(true) + (int) ($timeout * 1_000_000_000);

        try {
            foreach ($processes as $process) {
                $pid = $process->start();

                if ($pid === false) {
                    throw new RuntimeException('Unable to start cache concurrency child.');
                }

                $pids[$pid] = $process;
                $process->setBlocking(false);
            }

            $this->waitForReadyProcesses($ready, $count, $pids, $reaped, $deadline);
            $start->set(1);

            foreach ($pids as $pid => $process) {
                $payload = $this->readChildPayload($process, $pid, $reaped, $deadline);

                if (($payload['ok'] ?? false) !== true) {
                    throw new RuntimeException(
                        'Child process failed: ' . ($payload['error'] ?? 'unknown error'),
                    );
                }

                $results[] = $payload['result'] ?? null;
            }
        } catch (Throwable $throwable) {
            $exception = $throwable;
        } finally {
            $start->set(1);

            try {
                $this->cleanupChildProcesses($pids, $reaped);
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $results;
    }

    /**
     * Wait until every child process reaches the start barrier.
     */
    private function waitForReadyProcesses(
        Atomic $ready,
        int $count,
        array $pids,
        array &$reaped,
        int $deadline,
    ): void {
        while ($ready->get() < $count) {
            foreach ($pids as $pid => $process) {
                if ($this->reapIfExited($pid, $reaped)) {
                    throw new RuntimeException(
                        "Cache concurrency child [{$pid}] exited before reaching the start barrier.",
                    );
                }
            }

            if (hrtime(true) >= $deadline) {
                throw new RuntimeException(
                    "Only {$ready->get()} of {$count} child processes reached the start barrier.",
                );
            }

            usleep(100);
        }
    }

    /**
     * Write one length-prefixed payload to the parent process.
     */
    private function writeChildPayload(Process $process, array $payload): void
    {
        $serialized = serialize($payload);

        if (strlen($serialized) > self::MAX_FRAME_BYTES) {
            throw new RuntimeException('Cache concurrency child payload exceeds the maximum frame size.');
        }

        $frame = pack('N', strlen($serialized)) . $serialized;
        $offset = 0;

        while ($offset < strlen($frame)) {
            $written = $process->write(substr($frame, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write cache concurrency child payload.');
            }

            $offset += $written;
        }
    }

    /**
     * Read one complete length-prefixed child payload.
     */
    private function readChildPayload(
        Process $process,
        int $pid,
        array &$reaped,
        int $deadline,
    ): array {
        $buffer = '';
        $frameLength = null;

        while (hrtime(true) < $deadline) {
            $chunk = $process->read(8192);

            if (is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;

                if ($frameLength === null && strlen($buffer) >= self::FRAME_HEADER_BYTES) {
                    $header = unpack('Nlength', substr($buffer, 0, self::FRAME_HEADER_BYTES));
                    $frameLength = $header['length'];

                    if ($frameLength > self::MAX_FRAME_BYTES) {
                        throw new RuntimeException(
                            "Cache concurrency child [{$pid}] sent an oversized payload.",
                        );
                    }
                }

                if ($frameLength !== null
                    && strlen($buffer) >= self::FRAME_HEADER_BYTES + $frameLength
                ) {
                    $frameSize = self::FRAME_HEADER_BYTES + $frameLength;

                    if (strlen($buffer) !== $frameSize) {
                        throw new RuntimeException(
                            "Cache concurrency child [{$pid}] sent trailing payload data.",
                        );
                    }

                    $payload = unserialize(
                        substr($buffer, self::FRAME_HEADER_BYTES, $frameLength),
                        ['allowed_classes' => false],
                    );

                    if (! is_array($payload)) {
                        throw new RuntimeException(
                            "Cache concurrency child [{$pid}] sent an invalid payload.",
                        );
                    }

                    return $payload;
                }
            }

            if ($this->reapIfExited($pid, $reaped)) {
                throw new RuntimeException(
                    "Cache concurrency child [{$pid}] exited before sending a complete payload.",
                );
            }

            usleep(1_000);
        }

        throw new RuntimeException("Timed out waiting for cache concurrency child [{$pid}].");
    }

    /**
     * Stop, close, and reap every child process owned by this harness.
     */
    private function cleanupChildProcesses(array $pids, array &$reaped): void
    {
        foreach ($pids as $pid => $process) {
            if (! isset($reaped[$pid]) && Process::kill($pid, 0)) {
                Process::kill($pid, SIGKILL);
            }

            $process->close();
        }

        $deadline = hrtime(true) + 1_000_000_000;

        foreach (array_keys($pids) as $pid) {
            while (! isset($reaped[$pid])) {
                if ($this->reapIfExited($pid, $reaped)) {
                    break;
                }

                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException(
                        "Timed out reaping cache concurrency child [{$pid}].",
                    );
                }

                usleep(1_000);
            }
        }
    }

    /**
     * Reap an owned child if it has exited.
     */
    private function reapIfExited(int $pid, array &$reaped): bool
    {
        if (isset($reaped[$pid])) {
            return true;
        }

        $status = 0;
        $result = pcntl_waitpid($pid, $status, WNOHANG);

        if ($result === $pid) {
            $reaped[$pid] = true;

            return true;
        }

        if ($result === -1) {
            $error = pcntl_get_last_error();

            if ($error === PCNTL_ECHILD) {
                $reaped[$pid] = true;

                return true;
            }

            if ($error !== PCNTL_EINTR) {
                throw new RuntimeException(
                    "Unable to reap cache concurrency child [{$pid}]: " . pcntl_strerror($error),
                );
            }
        }

        return false;
    }

    private function createState(): SwooleTableState
    {
        return (new SwooleTableManager(m::mock(Container::class)))
            ->createState(128, 10240, 0.2, 12345);
    }

    private function createLockState(): LockTestSwooleTableState
    {
        return new LockTestSwooleTableState(
            $this->createState()->table(),
            12345,
        );
    }

    private function createStore(SwooleTableState $state): SwooleStore
    {
        return new SwooleStore($state, 0.05, SwooleStore::EVICTION_POLICY_TTL, 0.05);
    }

    private function tableKey(SwooleStore $store, string $method, string $key): string
    {
        $reflection = new ReflectionMethod($store, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($store, $key);
    }
}

class LockTestSwooleTableState extends SwooleTableState
{
    protected const int LOCK_ACQUIRE_TIMEOUT_NANOSECONDS = 50_000_000;

    public function holdLockFor(string $key): void
    {
        $this->lockFor($key)->set(1);
    }

    public function releaseLockFor(string $key): void
    {
        $this->lockFor($key)->set(0);
    }
}

class FailingAllStripeSwooleTableState extends SwooleTableState
{
    private int $acquisitions = 0;

    protected function acquire(Atomic $lock): void
    {
        if (++$this->acquisitions === 3) {
            throw new RuntimeException('Synthetic stripe acquisition failure.');
        }

        parent::acquire($lock);
    }

    public function firstAcquiredStripesAreReleased(): bool
    {
        return $this->rowLocks[0]->get() === 0
            && $this->rowLocks[1]->get() === 0;
    }
}
