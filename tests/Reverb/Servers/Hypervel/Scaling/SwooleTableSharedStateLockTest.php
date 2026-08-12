<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Core\Swoole\StripedLock;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Swoole\Atomic;
use Swoole\Coroutine\Channel;
use Swoole\Table;
use Throwable;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\run;

class SwooleTableSharedStateLockTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testFailedLockRowsAreReportedOnlyAfterTheirStripeIsReleased(): void
    {
        $locks = new ProbeStripedLock;
        $state = $this->createState(ReportingProbeSharedState::class, $locks);

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
        $locks = new ProbeStripedLock;
        $state = $this->createState(AtomicPresenceProbeSharedState::class, $locks);
        [$channel, $userId] = $state->presenceIdentityForSharedStripe();

        $result = $state->subscribe('app', $channel, $userId);

        $this->assertTrue($result->channelOccupied);
        $this->assertTrue($result->memberAdded);
        $this->assertSame(1, $locks->acquisitions);
        $this->assertSame(1, $locks->releases);
    }

    public function testOppositePresenceStripeOrderCannotDeadlock(): void
    {
        $state = $this->createState(AtomicPresenceProbeSharedState::class, new ProbeStripedLock);
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
     * Create a shared-state test double with real Swoole tables.
     *
     * @template T of SwooleTableSharedState
     * @param class-string<T> $class
     * @return T
     */
    private function createState(string $class, ProbeStripedLock $locks): SwooleTableSharedState
    {
        $table = new Table(128);
        $table->column('count', Table::TYPE_INT);
        $table->create();

        $lockTable = new Table(128);
        $lockTable->column('locked_at', Table::TYPE_FLOAT);
        $lockTable->create();

        return new $class($table, $lockTable, $locks);
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
            || $this->locks->isLocked($key);
    }
}

class AtomicPresenceProbeSharedState extends SwooleTableSharedState
{
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
            $this->locks->stripe($this->physicalKey(self::SUBSCRIPTION_KEY_TYPE, 'app', $channel)),
            $this->locks->stripe($this->physicalKey(self::USER_KEY_TYPE, 'app', $channel, $userId)),
        ];
    }
}

class ProbeStripedLock extends StripedLock
{
    public int $acquisitions = 0;

    public int $releases = 0;

    public function stripe(string $key): int
    {
        return $this->lockIndexFor($key);
    }

    public function isLocked(string $key): bool
    {
        return $this->lockFor($key)->get() !== 0;
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
}
