<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Core\Swoole\StripedLock;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState;
use Hypervel\Support\Facades\Log;
use Hypervel\Tests\Reverb\ReverbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Table;

class SwooleTableSharedStateTest extends ReverbTestCase
{
    protected SwooleTableSharedState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = $this->createState(1024, 256);
    }

    public function testSubscribeReturnsChannelOccupiedOnFirstSubscriber(): void
    {
        $result = $this->state->subscribe('app1', 'test-channel');

        $this->assertTrue($result->channelOccupied);
        $this->assertFalse($result->channelVacated);
        $this->assertFalse($result->memberAdded);
        $this->assertFalse($result->memberRemoved);
    }

    public function testSubscribeReturnsChannelNotOccupiedOnSubsequentSubscriber(): void
    {
        $this->state->subscribe('app1', 'test-channel');
        $result = $this->state->subscribe('app1', 'test-channel');

        $this->assertFalse($result->channelOccupied);
    }

    public function testUnsubscribeReturnsChannelVacatedOnLastSubscriber(): void
    {
        $this->state->subscribe('app1', 'test-channel');
        $result = $this->state->unsubscribe('app1', 'test-channel');

        $this->assertTrue($result->channelVacated);
        $this->assertFalse($result->channelOccupied);
    }

    public function testUnsubscribeReturnsChannelNotVacatedWithRemainingSubscribers(): void
    {
        $this->state->subscribe('app1', 'test-channel');
        $this->state->subscribe('app1', 'test-channel');
        $result = $this->state->unsubscribe('app1', 'test-channel');

        $this->assertFalse($result->channelVacated);
    }

    public function testSubscribeReturnsMemberAddedOnFirstUserInstance(): void
    {
        $result = $this->state->subscribe('app1', 'presence-channel', 'user-1');

        $this->assertTrue($result->memberAdded);
        $this->assertFalse($result->memberRemoved);
    }

    public function testSubscribeReturnsMemberNotAddedOnDuplicateUser(): void
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $result = $this->state->subscribe('app1', 'presence-channel', 'user-1');

        $this->assertFalse($result->memberAdded);
    }

    public function testUnsubscribeReturnsMemberRemovedOnLastUserInstance(): void
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $result = $this->state->unsubscribe('app1', 'presence-channel', 'user-1');

        $this->assertTrue($result->memberRemoved);
    }

    public function testUnsubscribeReturnsMemberNotRemovedWithRemainingUserInstance(): void
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $result = $this->state->unsubscribe('app1', 'presence-channel', 'user-1');

        $this->assertFalse($result->memberRemoved);
    }

    public function testSubscribeWithoutUserIdDoesNotTrackMembers(): void
    {
        $result = $this->state->subscribe('app1', 'test-channel');

        $this->assertFalse($result->memberAdded);
    }

    public function testDifferentAppsHaveIsolatedState(): void
    {
        $result1 = $this->state->subscribe('app1', 'test-channel');
        $result2 = $this->state->subscribe('app2', 'test-channel');

        $this->assertTrue($result1->channelOccupied);
        $this->assertTrue($result2->channelOccupied);
    }

    public function testLongLogicalKeysUseBoundedPhysicalKeys(): void
    {
        $appId = str_repeat('app:', 32);
        $channel = str_repeat('channel:', 32);
        $userId = str_repeat('user:', 32);

        $result = $this->state->subscribe($appId, $channel, $userId);

        $this->assertTrue($result->channelOccupied);
        $this->assertTrue($result->memberAdded);
        $this->assertSame(1, $this->state->getSubscriptionCount($appId, $channel));
        $this->assertSame(1, $this->state->getUserSubscriptionCount($appId, $channel, $userId));

        foreach ($this->state->table() as $key => $row) {
            $this->assertLessThanOrEqual(63, strlen($key));
        }
    }

    public function testDelimiterBearingLogicalKeysRemainDistinct(): void
    {
        $first = $this->state->subscribe('app:one', 'channel');
        $second = $this->state->subscribe('app', 'one:channel');

        $this->assertTrue($first->channelOccupied);
        $this->assertTrue($second->channelOccupied);
        $this->assertSame(1, $this->state->getSubscriptionCount('app:one', 'channel'));
        $this->assertSame(1, $this->state->getSubscriptionCount('app', 'one:channel'));
        $this->assertTrue($this->state->trySubscriptionCountLock('app:one', 'channel'));
        $this->assertTrue($this->state->trySubscriptionCountLock('app', 'one:channel'));
    }

    public function testChannelAndMemberSmoothingMarkersRemainDistinct(): void
    {
        $this->state->setSmoothingPending('app', 'channel:user', 5000);
        $this->state->setMemberSmoothingPending('app', 'channel', 'user', 5000);

        $this->assertTrue($this->state->clearSmoothingPending('app', 'channel:user', 5000));
        $this->assertTrue($this->state->clearMemberSmoothingPending('app', 'channel', 'user', 5000));
    }

    public function testAcquireConnectionSlotSucceedsWithinLimit(): void
    {
        $this->assertTrue($this->state->acquireConnectionSlot('app1', 5));
        $this->assertTrue($this->state->acquireConnectionSlot('app1', 5));
    }

    public function testAcquireConnectionSlotFailsAtLimit(): void
    {
        $this->state->acquireConnectionSlot('app1', 1);

        $this->assertFalse($this->state->acquireConnectionSlot('app1', 1));
    }

    public function testReleaseConnectionSlotFreesCapacity(): void
    {
        $this->state->acquireConnectionSlot('app1', 1);

        $this->assertFalse($this->state->acquireConnectionSlot('app1', 1));

        $this->state->releaseConnectionSlot('app1');

        $this->assertTrue($this->state->acquireConnectionSlot('app1', 1));
    }

    public function testReleaseConnectionSlotIsSafeWhenNoSlotAcquired(): void
    {
        $this->state->releaseConnectionSlot('app1');

        $this->assertTrue($this->state->acquireConnectionSlot('app1', 1));
        $this->assertFalse($this->state->acquireConnectionSlot('app1', 1));
    }

    public function testThrowsExceptionWhenTableIsFull(): void
    {
        $state = $this->createState(4, 4);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('reverb.servers.reverb.swoole_shared_state.rows');

        // Fill the table beyond capacity
        for ($i = 0; $i < 100; ++$i) {
            $state->subscribe('app1', "channel-{$i}");
        }
    }

    public function testFailedOpenDoesNotLeakConnectionSlot(): void
    {
        $this->state->acquireConnectionSlot('app1', 2);

        // Simulate a failed open — release the slot
        $this->state->releaseConnectionSlot('app1');

        // Both slots should be available
        $this->assertTrue($this->state->acquireConnectionSlot('app1', 2));
        $this->assertTrue($this->state->acquireConnectionSlot('app1', 2));
        $this->assertFalse($this->state->acquireConnectionSlot('app1', 2));
    }

    public function testUnsubscribeCleansUpZeroCountRows(): void
    {
        $this->state->subscribe('app1', 'test-channel', 'user-1');
        $this->state->unsubscribe('app1', 'test-channel', 'user-1');

        // Re-subscribe — should get channelOccupied again since row was cleaned
        $result = $this->state->subscribe('app1', 'test-channel', 'user-1');

        $this->assertTrue($result->channelOccupied);
        $this->assertTrue($result->memberAdded);
    }

    public function testPresenceCreationFailureDoesNotPublishOnlyOneCounter(): void
    {
        $state = $this->createState(128, 128, FailingSecondPresenceRowSharedState::class);

        try {
            $state->subscribe('app1', 'presence-channel', 'user-1');
            $this->fail('Expected the second presence row creation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to create the second presence row.', $exception->getMessage());
        }

        $this->assertSame(0, $state->table()->count());
    }

    #[DataProvider('warningModes')]
    public function testMemberAllocationFailurePreservesChannelCount(string $displayErrors): void
    {
        $state = $this->createState(64, 64);
        $previous = ini_set('swoole.display_errors', $displayErrors);
        $subscriptions = 0;

        try {
            for ($index = 0; $index < 200; ++$index) {
                $state->subscribe('app', 'presence', "user-{$index}");
                ++$subscriptions;
            }
            $this->fail('Expected the bounded counter table to fill.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('swoole_shared_state.rows', $exception->getMessage());
        } finally {
            ini_set('swoole.display_errors', $previous);
        }

        // Both rows exist. The preceding capacity error must not turn a duplicate into a failure.
        $state->subscribe('app', 'presence', 'user-0');
        $this->assertSame($subscriptions + 1, $state->getSubscriptionCount('app', 'presence'));
        $this->assertSame(2, $state->getUserSubscriptionCount('app', 'presence', 'user-0'));
    }

    #[DataProvider('warningModes')]
    public function testPresenceAllocationFailureRollsBackChannelUnderCustomErrorHandler(string $displayErrors): void
    {
        $state = $this->createState(64, 64);
        $table = $state->table();
        $capacity = $this->fillTable($table);

        // Free one conflict slice while keeping every root bucket occupied so the channel row allocates and the user row fails.
        for ($index = 0; $index < 10_000; ++$index) {
            $fillerKey = "filler:{$index}";

            if (! $table->del($fillerKey)) {
                continue;
            }

            if ($table->stats()['available_slice_num'] === 1) {
                break;
            }

            @$table->set($fillerKey, []);
        }

        $this->assertSame(1, $table->stats()['available_slice_num']);

        $handlerCalls = 0;
        $handler = static function () use (&$handlerCalls): bool {
            ++$handlerCalls;
            swoole_clear_error();

            return false;
        };

        $previousDisplayErrors = ini_set('swoole.display_errors', $displayErrors);
        set_error_handler($handler);
        $exception = null;
        $handlerAfterFailure = null;

        try {
            $state->subscribe('app', 'presence-rollback', 'user-1');
        } catch (RuntimeException $caught) {
            $exception = $caught;
            $handlerAfterFailure = get_error_handler();
        } finally {
            restore_error_handler();
            ini_set('swoole.display_errors', $previousDisplayErrors);
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertStringContainsString('swoole_shared_state.rows', $exception->getMessage());
        $this->assertSame($handler, $handlerAfterFailure);
        $this->assertSame(0, $handlerCalls);
        $this->assertSame($capacity - 1, $table->count());
        $this->assertSame(0, $state->getSubscriptionCount('app', 'presence-rollback'));
    }

    // ── Subscription count ────────────────────────────────────────────

    public function testSubscribeReturnsCorrectSubscriptionCount(): void
    {
        $result1 = $this->state->subscribe('app1', 'test-channel');
        $this->assertSame(1, $result1->subscriptionCount);

        $result2 = $this->state->subscribe('app1', 'test-channel');
        $this->assertSame(2, $result2->subscriptionCount);

        $result3 = $this->state->subscribe('app1', 'test-channel');
        $this->assertSame(3, $result3->subscriptionCount);
    }

    public function testUnsubscribeReturnsCorrectSubscriptionCount(): void
    {
        $this->state->subscribe('app1', 'test-channel');
        $this->state->subscribe('app1', 'test-channel');
        $this->state->subscribe('app1', 'test-channel');

        $result = $this->state->unsubscribe('app1', 'test-channel');
        $this->assertSame(2, $result->subscriptionCount);

        $result = $this->state->unsubscribe('app1', 'test-channel');
        $this->assertSame(1, $result->subscriptionCount);

        $result = $this->state->unsubscribe('app1', 'test-channel');
        $this->assertSame(0, $result->subscriptionCount);
    }

    public function testGetSubscriptionCountReturnsZeroForUnknownChannel(): void
    {
        $this->assertSame(0, $this->state->getSubscriptionCount('app1', 'nonexistent'));
    }

    public function testGetSubscriptionCountReturnsCurrentCount(): void
    {
        $this->state->subscribe('app1', 'test-channel');
        $this->state->subscribe('app1', 'test-channel');

        $this->assertSame(2, $this->state->getSubscriptionCount('app1', 'test-channel'));
    }

    public function testGetSubscriptionCountReturnsZeroAfterAllUnsubscribe(): void
    {
        $this->state->subscribe('app1', 'test-channel');
        $this->state->unsubscribe('app1', 'test-channel');

        $this->assertSame(0, $this->state->getSubscriptionCount('app1', 'test-channel'));
    }

    // ── User subscription count ───────────────────────────────────────

    public function testGetUserSubscriptionCountReturnsZeroForUnknownUser(): void
    {
        $this->assertSame(0, $this->state->getUserSubscriptionCount('app1', 'presence-channel', 'unknown'));
    }

    public function testGetUserSubscriptionCountReturnsCurrentCount(): void
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $this->state->subscribe('app1', 'presence-channel', 'user-1');

        $this->assertSame(2, $this->state->getUserSubscriptionCount('app1', 'presence-channel', 'user-1'));
    }

    public function testGetUserSubscriptionCountReturnsZeroAfterAllUnsubscribe(): void
    {
        $this->state->subscribe('app1', 'presence-channel', 'user-1');
        $this->state->unsubscribe('app1', 'presence-channel', 'user-1');

        $this->assertSame(0, $this->state->getUserSubscriptionCount('app1', 'presence-channel', 'user-1'));
    }

    // ── Subscription count lock ───────────────────────────────────────

    public function testTrySubscriptionCountLockAcquiresOnFirstCall(): void
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
    }

    public function testTrySubscriptionCountLockFailsWithinTtl(): void
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
        $this->assertFalse($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
    }

    public function testTrySubscriptionCountLockSucceedsAfterTtlExpires(): void
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 50));

        usleep(60_000); // 60ms — well past the 50ms TTL

        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 50));
    }

    public function testClearSubscriptionCountLockAllowsReacquire(): void
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
        $this->assertFalse($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));

        $this->state->clearSubscriptionCountLock('app1', 'test-channel');

        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
    }

    // ── Cache miss lock ───────────────────────────────────────────────

    public function testTryCacheMissLockAcquiresOnFirstCall(): void
    {
        $this->assertTrue($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
    }

    public function testTryCacheMissLockFailsWithinTtl(): void
    {
        $this->assertTrue($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
        $this->assertFalse($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
    }

    public function testClearCacheMissLockAllowsReacquire(): void
    {
        $this->assertTrue($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
        $this->assertFalse($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));

        $this->state->clearCacheMissLock('app1', 'cache-channel');

        $this->assertTrue($this->state->tryCacheMissLock('app1', 'cache-channel', 10000));
    }

    // ── Lock isolation ────────────────────────────────────────────────

    public function testLocksAreIsolatedBetweenApps(): void
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'test-channel', 5000));
        $this->assertTrue($this->state->trySubscriptionCountLock('app2', 'test-channel', 5000));
    }

    public function testLocksAreIsolatedBetweenChannels(): void
    {
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'channel-a', 5000));
        $this->assertTrue($this->state->trySubscriptionCountLock('app1', 'channel-b', 5000));
    }

    // ── Smoothing markers ─────────────────────────────────────────────

    public function testClearSmoothingPendingReturnsTrueForLiveMarker(): void
    {
        $this->state->setSmoothingPending('app1', 'test-channel', 5000);

        $this->assertTrue($this->state->clearSmoothingPending('app1', 'test-channel', 5000));
    }

    public function testClearSmoothingPendingReturnsFalseWhenNoMarker(): void
    {
        $this->assertFalse($this->state->clearSmoothingPending('app1', 'test-channel', 5000));
    }

    public function testClearSmoothingPendingReturnsFalseForExpiredMarker(): void
    {
        $this->state->setSmoothingPending('app1', 'test-channel', 50);

        usleep(60_000); // 60ms — past the 50ms TTL

        $this->assertFalse($this->state->clearSmoothingPending('app1', 'test-channel', 50));
    }

    public function testClearSmoothingPendingConsumesMarkerOnlyOnce(): void
    {
        $this->state->setSmoothingPending('app1', 'test-channel', 5000);

        $this->assertTrue($this->state->clearSmoothingPending('app1', 'test-channel', 5000));
        $this->assertFalse($this->state->clearSmoothingPending('app1', 'test-channel', 5000));
    }

    public function testClearMemberSmoothingPendingReturnsTrueForLiveMarker(): void
    {
        $this->state->setMemberSmoothingPending('app1', 'presence-channel', 'user-1', 5000);

        $this->assertTrue($this->state->clearMemberSmoothingPending('app1', 'presence-channel', 'user-1', 5000));
    }

    public function testClearMemberSmoothingPendingReturnsFalseWhenNoMarker(): void
    {
        $this->assertFalse($this->state->clearMemberSmoothingPending('app1', 'presence-channel', 'user-1', 5000));
    }

    public function testClearMemberSmoothingPendingReturnsFalseForExpiredMarker(): void
    {
        $this->state->setMemberSmoothingPending('app1', 'presence-channel', 'user-1', 50);

        usleep(60_000);

        $this->assertFalse($this->state->clearMemberSmoothingPending('app1', 'presence-channel', 'user-1', 50));
    }

    // ── Lock table capacity ───────────────────────────────────────────

    #[DataProvider('warningModes')]
    public function testFullWebhookTableReportsThrottleAndSmoothingFailures(string $displayErrors): void
    {
        $state = $this->createState(1024, 4);
        $this->fillTable($state->lockTable());

        foreach (['m', 'h', 'p'] as $prefix) {
            Log::shouldReceive('error')->once()->withArgs(
                fn (string $message): bool => str_contains($message, 'swoole_shared_state.lock_rows')
                    && str_contains($message, "key [{$prefix}"),
            );
        }

        $previous = ini_set('swoole.display_errors', $displayErrors);

        try {
            $this->assertFalse($state->tryCacheMissLock('app', 'channel'));
            $state->setSmoothingPending('app', 'channel', 1_000);
            $state->setMemberSmoothingPending('app', 'channel', 'user', 1_000);
        } finally {
            ini_set('swoole.display_errors', $previous);
        }
    }

    #[DataProvider('warningModes')]
    public function testFullWebhookTableIgnoresCustomErrorHandlerOnThrottleInsert(string $displayErrors): void
    {
        $state = $this->createState(1024, 4);
        $this->fillTable($state->lockTable());

        Log::shouldReceive('error')->once()->withArgs(
            fn (string $message): bool => str_contains($message, 'swoole_shared_state.lock_rows')
                && str_contains($message, 'key [m'),
        );

        $handlerCalls = 0;
        $handler = static function () use (&$handlerCalls): bool {
            if (++$handlerCalls > 1) {
                throw new RuntimeException('Throttle lock retried a full table after a custom error handler cleared swoole_last_error().');
            }

            swoole_clear_error();

            return false;
        };

        $previousDisplayErrors = ini_set('swoole.display_errors', $displayErrors);
        set_error_handler($handler);

        try {
            $this->assertFalse($state->tryCacheMissLock('app', 'channel'));
            $this->assertSame($handler, get_error_handler());
        } finally {
            restore_error_handler();
            ini_set('swoole.display_errors', $previousDisplayErrors);
        }

        $this->assertSame(0, $handlerCalls);
    }

    /**
     * Exercise both settings for Swoole's allocation warnings.
     */
    public static function warningModes(): array
    {
        return [['1'], ['0']];
    }

    public function testExpiredThrottleRechecksAConcurrentClearOrRenewal(): void
    {
        $table = new class(64) extends Table {
            public bool $clearOnCompare = true;

            /**
             * Interleave a competing mutation after the throttle read.
             */
            public function cmpset(string $key, array $expected, array $values): bool
            {
                if ($this->clearOnCompare) {
                    $this->del($key);
                } else {
                    $this->set($key, ['locked_at' => microtime(true)]);
                }

                return parent::cmpset($key, $expected, $values);
            }
        };
        $table->column('locked_at', Table::TYPE_FLOAT);
        $table->create();
        $this->trackSwooleTable($table);
        $state = new SwooleTableSharedState($this->state->table(), $table, new StripedLock);

        $this->assertTrue($state->tryCacheMissLock('app', 'channel'));
        $key = array_key_first(iterator_to_array($table));

        $table->set($key, ['locked_at' => 0.0]);
        $this->assertTrue($state->tryCacheMissLock('app', 'channel'));

        $table->clearOnCompare = false;
        $table->set($key, ['locked_at' => 0.0]);
        $this->assertFalse($state->tryCacheMissLock('app', 'channel'));
    }

    /**
     * Create a shared state backed by tracked tables.
     *
     * @template T of SwooleTableSharedState
     * @param class-string<T> $class
     * @return T
     */
    private function createState(int $rows, int $lockRows, string $class = SwooleTableSharedState::class): SwooleTableSharedState
    {
        $table = new Table($rows);
        $table->column('count', Table::TYPE_INT);
        $table->create();

        $lockTable = new Table($lockRows);
        $lockTable->column('locked_at', Table::TYPE_FLOAT);
        $lockTable->create();

        $this->trackSwooleTable($table, $lockTable);

        return new $class($table, $lockTable, new StripedLock);
    }

    /**
     * Fill all buckets and conflict slices of the given table and return its capacity.
     */
    private function fillTable(Table $table): int
    {
        $capacity = $table->getSize() + $table->stats()['total_slice_num'];

        for ($index = 0; $table->count() < $capacity && $index < 10_000; ++$index) {
            @$table->set("filler:{$index}", []);
        }

        $this->assertSame($capacity, $table->count());

        return $capacity;
    }
}

class FailingSecondPresenceRowSharedState extends SwooleTableSharedState
{
    private int $rowCreations = 0;

    protected function ensureRowExists(string $key): bool
    {
        if (++$this->rowCreations === 2) {
            throw new RuntimeException('Unable to create the second presence row.');
        }

        return parent::ensureRowExists($key);
    }
}
