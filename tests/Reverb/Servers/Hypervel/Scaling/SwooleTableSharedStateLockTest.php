<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Closure;
use Hypervel\Core\Swoole\StripedLock;
use Hypervel\Foundation\Testing\Concerns\InteractsWithSwooleTables;
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
    use InteractsWithSwooleTables;

    protected bool $runTestsInCoroutine = false;

    public function testRejectedAdmissionDoesNotConsumeAReleasedSlot(): void
    {
        $locks = new ProbeStripedLock;
        $state = $this->createState(SwooleTableSharedState::class, $locks);
        $this->assertTrue($state->acquireConnectionSlot('app', 1));

        $admitted = false;
        $locks->afterRelease = function () use ($state, &$admitted): void {
            $state->releaseConnectionSlot('app');
            $admitted = $state->acquireConnectionSlot('app', 1);
        };

        $this->assertFalse($state->acquireConnectionSlot('app', 1));
        $this->assertTrue($admitted);
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

        $this->trackSwooleTable($table, $lockTable);

        return new $class($table, $lockTable, $locks);
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
    public ?Closure $afterRelease = null;

    public int $acquisitions = 0;

    public int $releases = 0;

    public function stripe(string $key): int
    {
        return $this->lockIndexFor($key);
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

        if ($callback = $this->afterRelease) {
            $this->afterRelease = null;
            $callback();
        }
    }
}
