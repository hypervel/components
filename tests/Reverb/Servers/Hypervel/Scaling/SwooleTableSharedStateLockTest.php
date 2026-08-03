<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Swoole\Atomic;
use Swoole\Coroutine\Channel;
use Swoole\Process;
use Swoole\Table;
use Throwable;

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

                    $chunk = $process->read();

                    if (is_string($chunk) && $chunk !== '') {
                        $message .= $chunk;
                    }

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

        if ($message === '') {
            $this->fail($reaped
                ? "Reverb lock child [{$pid}] exited without reporting a message."
                : "Timed out after 250ms waiting for Reverb lock child [{$pid}] to report.");
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

        $this->assertSame(['m', 'h', 'p'], array_map(
            static fn (string $key): string => $key[0],
            $state->reportedKeys,
        ));
        $this->assertSame([33, 33, 33], array_map('strlen', $state->reportedKeys));
        $this->assertFalse($state->reportedWhileLocked);
    }

    public function testPresenceMutationAcquiresSharedStripeOnlyOnce(): void
    {
        $state = $this->createState(AtomicPresenceProbeSharedState::class);
        [$channel, $userId] = $state->presenceIdentityForSharedStripe();

        $result = $state->subscribe('app', $channel, $userId);

        $this->assertTrue($result->channelOccupied);
        $this->assertTrue($result->memberAdded);
        $this->assertSame(1, $state->acquisitions);
        $this->assertSame(1, $state->releases);
    }

    public function testOppositePresenceStripeOrderCannotDeadlock(): void
    {
        $state = $this->createState(AtomicPresenceProbeSharedState::class);
        [$first, $second] = $state->oppositePresenceIdentities();
        $results = new Channel(2);
        $outcomes = [];

        run(function () use ($state, $first, $second, $results, &$outcomes): void {
            foreach ([$first, $second] as [$channel, $userId]) {
                go(function () use ($state, $channel, $userId, $results): void {
                    try {
                        $state->subscribe('app', $channel, $userId);
                        $results->push(true);
                    } catch (Throwable $exception) {
                        $results->push($exception);
                    }
                });
            }

            $outcomes[] = $results->pop(2);
            $outcomes[] = $results->pop(2);
        });

        $this->assertSame([true, true], $outcomes);
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
    protected const int LOCK_ACQUIRE_TIMEOUT_NANOSECONDS = 50_000_000;

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

class AtomicPresenceProbeSharedState extends SwooleTableSharedState
{
    public int $acquisitions = 0;

    public int $releases = 0;

    /**
     * Find a channel/member identity whose rows share one stripe.
     *
     * @return array{string, string}
     */
    public function presenceIdentityForSharedStripe(): array
    {
        for ($index = 0; $index < 10_000; ++$index) {
            $channel = "channel-{$index}";
            $userId = "user-{$index}";

            if ($this->presenceStripePair($channel, $userId)[0]
                === $this->presenceStripePair($channel, $userId)[1]) {
                return [$channel, $userId];
            }
        }

        throw new RuntimeException('Unable to find a shared-stripe presence identity.');
    }

    /**
     * Find two identities that encounter the same stripes in opposite order.
     *
     * @return array{array{string, string}, array{string, string}}
     */
    public function oppositePresenceIdentities(): array
    {
        $identities = [];

        for ($index = 0; $index < 10_000; ++$index) {
            $channel = "channel-{$index}";
            $userId = "user-{$index}";
            [$channelStripe, $userStripe] = $this->presenceStripePair($channel, $userId);

            if ($channelStripe === $userStripe) {
                continue;
            }

            $opposite = "{$userStripe}:{$channelStripe}";

            if (isset($identities[$opposite])) {
                return [$identities[$opposite], [$channel, $userId]];
            }

            $identities["{$channelStripe}:{$userStripe}"] = [$channel, $userId];
        }

        throw new RuntimeException('Unable to find opposite-order presence identities.');
    }

    protected function acquire(Atomic $lock): void
    {
        parent::acquire($lock);
        ++$this->acquisitions;
    }

    protected function release(Atomic $lock): void
    {
        ++$this->releases;
        parent::release($lock);
    }

    protected function ensurePresenceRowsExist(string $channelKey, string $userKey): void
    {
        parent::ensurePresenceRowsExist($channelKey, $userKey);
        usleep(5_000);
    }

    /**
     * Get the channel/member stripe pair for a presence identity.
     *
     * @return array{int, int}
     */
    private function presenceStripePair(string $channel, string $userId): array
    {
        return [
            $this->lockIndexFor($this->physicalKey(self::SUBSCRIPTION_KEY_TYPE, 'app', $channel)),
            $this->lockIndexFor($this->physicalKey(self::USER_KEY_TYPE, 'app', $channel, $userId)),
        ];
    }
}
