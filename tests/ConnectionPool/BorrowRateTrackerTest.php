<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool;

use Hypervel\ConnectionPool\BorrowRateTracker;
use Hypervel\Tests\ConnectionPool\Fixtures\BorrowRateTrackerStub;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BorrowRateTrackerTest extends TestCase
{
    public function testDefaultClockUsesMonotonicSeconds(): void
    {
        $tracker = new class extends BorrowRateTracker {
            /**
             * Expose the default sampling clock.
             */
            public function currentTime(): int
            {
                return parent::currentTime();
            }
        };
        $before = intdiv(hrtime(true), 1_000_000_000);
        $sample = $tracker->currentTime();
        $after = intdiv(hrtime(true), 1_000_000_000);

        $this->assertGreaterThanOrEqual($before, $sample);
        $this->assertLessThanOrEqual($after, $sample);
    }

    public function testRateAndTrimmingRemainInactiveBeforeTheFirstBorrow(): void
    {
        $tracker = new BorrowRateTrackerStub;
        $tracker->now += 1000;

        $this->assertSame(0.0, $tracker->getBorrowRate());
        $this->assertFalse($tracker->shouldTrimExcessIdle());

        $tracker->recordBorrow();

        $this->assertSame(1.0, $tracker->getBorrowRate());
        $this->assertFalse($tracker->shouldTrimExcessIdle());
    }

    public function testEachBorrowImmediatelyUpdatesTheRate(): void
    {
        $tracker = new BorrowRateTrackerStub;
        $tracker->seed(96, [100 => 1, 99 => 10, 98 => 10, 97 => 10, 96 => 10]);

        $this->assertSame(41 / 5, $tracker->getBorrowRate());

        $tracker->recordBorrow();

        $this->assertSame(42 / 5, $tracker->getBorrowRate());
        $this->assertSame(array_sum($tracker->getSamples()), $tracker->getBorrowCount());
    }

    public function testMissingCompletedSecondsContributeToTheSampleDivisor(): void
    {
        $tracker = new BorrowRateTrackerStub;
        $tracker->seed(96, [100 => 1, 99 => 10, 98 => 10, 96 => 10]);

        $this->assertSame(31 / 5, $tracker->getBorrowRate());
        $tracker->recordBorrow();
        $this->assertSame(32 / 5, $tracker->getBorrowRate());

        $tracker->seed(96, [100 => 1, 99 => 10, 98 => 10, 97 => 10]);

        $this->assertSame(31 / 5, $tracker->getBorrowRate());
        $tracker->recordBorrow();
        $this->assertSame(32 / 5, $tracker->getBorrowRate());
    }

    public function testExpiredEleventhBucketIsRemovedFromTheRunningCount(): void
    {
        $tracker = new BorrowRateTrackerStub;
        $tracker->seed(90, array_fill_keys(range(91, 100), 0) + [90 => 100]);

        $this->assertSame(0.0, $tracker->getBorrowRate());
        $this->assertCount(10, $tracker->getSamples());
        $this->assertSame(0, $tracker->getBorrowCount());
    }

    public function testWarmupCountsOnlyCompletedSecondsAndSecondsWithBorrows(): void
    {
        $tracker = new BorrowRateTrackerStub;
        $tracker->recordBorrow();
        $tracker->recordBorrow();
        $tracker->now = 101;
        $this->assertSame(2.0, $tracker->getBorrowRate());

        $tracker->now = 102;
        $this->assertSame(1.0, $tracker->getBorrowRate());

        $tracker->recordBorrow();
        $this->assertSame(1.0, $tracker->getBorrowRate());
        $this->assertSame([100 => 2, 101 => 0, 102 => 1], $tracker->getSamples());
    }

    public function testCooldownStartsAtFirstBorrowAndKeepsItsStrictBoundary(): void
    {
        $tracker = new BorrowRateTrackerStub;
        $tracker->now = 1000;
        $tracker->recordBorrow();
        $tracker->now = 1060;
        $this->assertFalse($tracker->shouldTrimExcessIdle());

        $tracker->now = 1061;
        $this->assertTrue($tracker->shouldTrimExcessIdle());
        $this->assertFalse($tracker->shouldTrimExcessIdle());

        $tracker->now = 1121;
        $this->assertFalse($tracker->shouldTrimExcessIdle());
        $tracker->now = 1122;
        $this->assertTrue($tracker->shouldTrimExcessIdle());
    }

    public function testSameSecondBorrowsRemainVisibleAfterCooldownEligibility(): void
    {
        $tracker = new BorrowRateTrackerStub;
        $tracker->seed(1, array_fill_keys(range(91, 100), 5));

        $this->assertFalse($tracker->shouldTrimExcessIdle());
        $this->assertSame(5.0, $tracker->getBorrowRate());

        $tracker->recordBorrow();
        $tracker->recordBorrow();

        $this->assertSame(5.2, $tracker->getBorrowRate());
        $this->assertFalse($tracker->shouldTrimExcessIdle());

        $tracker->now = 101;
        $this->assertSame(47 / 9, $tracker->getBorrowRate());
        $this->assertFalse($tracker->shouldTrimExcessIdle());

        $tracker->now = 102;
        $this->assertTrue($tracker->shouldTrimExcessIdle());
    }

    #[DataProvider('sampleWindows')]
    public function testLongIdlePeriodsKeepSamplesAndCountsBounded(int $window): void
    {
        $tracker = new BorrowRateTrackerStub(window: $window);
        $tracker->recordBorrow();
        $tracker->now += 1_000_000;

        $this->assertSame(0.0, $tracker->getBorrowRate());
        $this->assertCount($window - 1, $tracker->getSamples());
        $this->assertTrue($tracker->shouldTrimExcessIdle());

        $tracker->recordBorrow();

        $this->assertSame(1.0 / $window, $tracker->getBorrowRate());
        $this->assertCount($window, $tracker->getSamples());
        $this->assertSame(1, $tracker->getBorrowCount());
    }

    public static function sampleWindows(): array
    {
        return [[1], [3], [10]];
    }

    public function testCustomThresholdAndCooldownAreRespected(): void
    {
        $tracker = new BorrowRateTrackerStub(window: 3, threshold: 2, cooldown: 2);
        $tracker->recordBorrow();
        $tracker->now = 102;
        $this->assertFalse($tracker->shouldTrimExcessIdle());
        $tracker->now = 103;
        $tracker->recordBorrow();
        $this->assertTrue($tracker->shouldTrimExcessIdle());
    }
}
