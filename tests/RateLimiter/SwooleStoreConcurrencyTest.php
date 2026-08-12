<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\Config\Repository;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\SlidingWindow;
use Hypervel\RateLimiter\Swoole\TableManager;
use Hypervel\RateLimiter\Swoole\TableState;
use Hypervel\RateLimiter\SwooleStore;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use RuntimeException;
use Swoole\Atomic;
use Swoole\Process;
use Throwable;

class SwooleStoreConcurrencyTest extends TestCase
{
    private const FRAME_HEADER_BYTES = 4;

    private const MAX_FRAME_BYTES = 1_048_576;

    protected bool $runTestsInCoroutine = false;

    #[DataProvider('admissionPolicyProvider')]
    public function testForkedWorkersAdmitExactlyTheConfiguredCapacity(AdmissionPolicy $policy): void
    {
        $state = $this->state();
        $processCount = 8;
        $attemptsPerProcess = 25;
        $ready = new Atomic(0);
        $start = new Atomic(0);
        $completed = new Atomic(0);
        $allowed = new Atomic(0);
        $processes = [];
        $pids = [];
        $deadline = hrtime(true) + 5_000_000_000;

        try {
            for ($processIndex = 0; $processIndex < $processCount; ++$processIndex) {
                $process = new Process(function (Process $process) use (
                    $state,
                    $policy,
                    $attemptsPerProcess,
                    $ready,
                    $start,
                    $completed,
                    $allowed,
                ): void {
                    $ready->add(1);

                    try {
                        while ($start->get() === 0) {
                            usleep(100);
                        }

                        $store = $this->store($state);

                        for ($attempt = 0; $attempt < $attemptsPerProcess; ++$attempt) {
                            if ($store->consume('workers', $policy)->allowed()) {
                                $allowed->add(1);
                            }
                        }

                        $payload = ['ok' => true];
                    } catch (Throwable $throwable) {
                        $payload = [
                            'ok' => false,
                            'class' => $throwable::class,
                            'message' => $throwable->getMessage(),
                            'trace' => $throwable->getTraceAsString(),
                        ];
                    } finally {
                        try {
                            $this->writeChildPayload($process, $payload ?? [
                                'ok' => false,
                                'class' => RuntimeException::class,
                                'message' => 'Child exited without producing a result.',
                                'trace' => '',
                            ]);
                        } finally {
                            $completed->add(1);

                            // Avoid PHPUnit/Testbench shutdown handlers inherited from the parent.
                            posix_kill(getmypid(), SIGKILL);
                        }
                    }
                }, false, SOCK_STREAM);

                $pid = $process->start();

                if ($pid === false) {
                    throw new RuntimeException('Unable to start a Swoole rate limiter concurrency child.');
                }

                $processes[$pid] = $process;
                $pids[] = $pid;
            }

            $this->waitForChildren($ready, $processCount, $deadline, 'start');
            $start->set(1);
            $this->waitForChildren($completed, $processCount, $deadline, 'finish');

            foreach ($processes as $pid => $process) {
                $payload = $this->readChildPayload($process, $pid, $deadline);

                if (($payload['ok'] ?? false) !== true) {
                    throw new RuntimeException(sprintf(
                        "Swoole rate limiter concurrency child [%d] failed: %s: %s\n%s",
                        $pid,
                        $payload['class'] ?? 'unknown exception',
                        $payload['message'] ?? 'unknown error',
                        $payload['trace'] ?? '',
                    ));
                }
            }

            $store = $this->store($state);

            $this->assertSame(50, $allowed->get());
            $this->assertSame(0, $store->inspect('workers', $policy)->remaining());
        } finally {
            $start->set(1);
            $this->cleanupProcesses($processes, $pids);
        }
    }

    public static function admissionPolicyProvider(): array
    {
        return [
            'fixed window' => [Limit::perMinute(50)],
            'sliding window' => [SlidingWindow::perMinute(50)],
        ];
    }

    /**
     * Wait until every child reaches a synchronization point.
     */
    private function waitForChildren(
        Atomic $counter,
        int $expected,
        int $deadline,
        string $stage,
    ): void {
        while ($counter->get() < $expected) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException("Timed out waiting for Swoole rate limiter children to {$stage}.");
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
            throw new RuntimeException('Swoole rate limiter concurrency child payload exceeds the maximum frame size.');
        }

        $frame = pack('N', strlen($serialized)) . $serialized;
        $offset = 0;

        while ($offset < strlen($frame)) {
            $written = $process->write(substr($frame, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write Swoole rate limiter concurrency child payload.');
            }

            $offset += $written;
        }
    }

    /**
     * Read one complete length-prefixed payload from a child process.
     */
    private function readChildPayload(Process $process, int $pid, int $deadline): array
    {
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
                            "Swoole rate limiter concurrency child [{$pid}] sent an oversized payload.",
                        );
                    }
                }

                if ($frameLength !== null
                    && strlen($buffer) >= self::FRAME_HEADER_BYTES + $frameLength
                ) {
                    $frameSize = self::FRAME_HEADER_BYTES + $frameLength;

                    if (strlen($buffer) !== $frameSize) {
                        throw new RuntimeException(
                            "Swoole rate limiter concurrency child [{$pid}] sent trailing payload data.",
                        );
                    }

                    $payload = unserialize(
                        substr($buffer, self::FRAME_HEADER_BYTES, $frameLength),
                        ['allowed_classes' => false],
                    );

                    if (! is_array($payload)) {
                        throw new RuntimeException(
                            "Swoole rate limiter concurrency child [{$pid}] sent an invalid payload.",
                        );
                    }

                    return $payload;
                }
            }

            usleep(1_000);
        }

        throw new RuntimeException("Timed out reading Swoole rate limiter concurrency child [{$pid}].");
    }

    /**
     * Stop and reap every child process owned by the test.
     *
     * @param array<int, Process> $processes
     * @param list<int> $pids
     */
    private function cleanupProcesses(array $processes, array $pids): void
    {
        foreach ($processes as $pid => $process) {
            if (Process::kill($pid, 0)) {
                Process::kill($pid, SIGKILL);
            }

            $process->close();
        }

        foreach ($pids as $pid) {
            $deadline = hrtime(true) + 1_000_000_000;

            do {
                $status = 0;
                $result = pcntl_waitpid($pid, $status, WNOHANG);

                if ($result === $pid || ($result === -1 && pcntl_get_last_error() === PCNTL_ECHILD)) {
                    continue 2;
                }

                if ($result === -1 && pcntl_get_last_error() !== PCNTL_EINTR) {
                    throw new RuntimeException(
                        "Unable to reap Swoole rate limiter child [{$pid}]: "
                        . pcntl_strerror(pcntl_get_last_error()),
                    );
                }

                usleep(1000);
            } while (hrtime(true) < $deadline);

            throw new RuntimeException("Timed out reaping Swoole rate limiter child [{$pid}].");
        }
    }

    /**
     * Create shared table state before any child process is forked.
     */
    private function state(): TableState
    {
        $manager = new TableManager(new Repository([
            'rate-limiter' => [
                'stores' => [
                    'swoole' => [
                        'driver' => 'swoole',
                        'rows' => 128,
                        'conflict_proportion' => 0.2,
                    ],
                ],
            ],
        ]));

        return $manager->get('swoole');
    }

    /**
     * Create a store over the pre-fork shared state.
     */
    private function store(TableState $state): SwooleStore
    {
        return new SwooleStore($state, 0.05, new NullLogger);
    }
}
