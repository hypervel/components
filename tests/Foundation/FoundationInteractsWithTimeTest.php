<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use DateInterval;
use Hypervel\Foundation\Testing\Concerns\InteractsWithTime;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\InteractsWithTime as SupportInteractsWithTime;
use Hypervel\Tests\TestCase;
use RuntimeException;

class FoundationInteractsWithTimeTest extends TestCase
{
    use InteractsWithTime;

    public function testFreezeTimeReturnsFrozenTime(): void
    {
        $actual = $this->freezeTime();

        $this->assertTrue(Carbon::hasTestNow());
        $this->assertSame(CarbonImmutable::class, $actual::class);
        $this->assertTrue(Carbon::getTestNow()->eq($actual));
    }

    public function testFreezeTimeHonorsTheMutableDateFactoryOptOut(): void
    {
        Date::use(Carbon::class);

        $actual = $this->freezeTime();

        $this->assertSame(Carbon::class, $actual::class);
        $this->assertTrue(Carbon::getTestNow()->eq($actual));
    }

    public function testFreezeTimeReturnsCallbackResult(): void
    {
        $actual = $this->freezeTime(function (): int {
            return 12345;
        });

        $this->assertSame(12345, $actual);
        $this->assertFalse(Carbon::hasTestNow());
    }

    public function testFreezeTimeReturnsCallbackResultEvenWhenNull(): void
    {
        $actual = $this->freezeTime(function (): null {
            return null;
        });

        $this->assertNull($actual);
        $this->assertFalse(Carbon::hasTestNow());
    }

    public function testFreezeSecondReturnsFrozenTime(): void
    {
        $actual = $this->freezeSecond();

        $this->assertTrue(Carbon::hasTestNow());
        $this->assertSame(CarbonImmutable::class, $actual::class);
        $this->assertTrue(Carbon::getTestNow()->eq($actual));
        $this->assertSame(0, $actual->milliseconds);
    }

    public function testFreezeSecondHonorsTheMutableDateFactoryOptOut(): void
    {
        Date::use(Carbon::class);

        $actual = $this->freezeSecond();

        $this->assertSame(Carbon::class, $actual::class);
        $this->assertTrue(Carbon::getTestNow()->eq($actual));
        $this->assertSame(0, $actual->milliseconds);
    }

    public function testFreezeSecondReturnsCallbackResult(): void
    {
        $actual = $this->freezeSecond(function (): int {
            return 12345;
        });

        $this->assertSame(12345, $actual);
        $this->assertFalse(Carbon::hasTestNow());
    }

    public function testFreezeSecondReturnsCallbackResultEvenWhenNull(): void
    {
        $actual = $this->freezeSecond(function (): null {
            return null;
        });

        $this->assertNull($actual);
        $this->assertFalse(Carbon::hasTestNow());
    }

    public function testFreezeTimeRestoresRealTimeWhenTheCallbackThrows(): void
    {
        $exception = new RuntimeException('callback failed');

        try {
            $this->freezeTime(static function () use ($exception): never {
                throw $exception;
            });
            $this->fail('Expected the callback to throw.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertFalse(Carbon::hasTestNow());
    }

    public function testSupportRuntimeFormatterUsesMillisecondsBelowOneSecond(): void
    {
        $formatter = new SupportInteractsWithTimeTestFixture;

        $this->assertSame('125.00ms', $formatter->runTimeForHumans(10.0, 10.125));
    }

    public function testSupportRuntimeFormatterCascadesLongerDurations(): void
    {
        $formatter = new SupportInteractsWithTimeTestFixture;

        $this->assertSame('1m 5s', $formatter->runTimeForHumans(10.0, 75.0));
    }

    public function testFutureIntegerDeadlinesRoundUpWithoutDelayingImmediateOrPastValues(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $formatter = new SupportInteractsWithTimeTestFixture;

        $this->assertSame(1002, $formatter->availableAt(1));
        $this->assertSame(1000, $formatter->availableAt());
        $this->assertSame(1000, $formatter->availableAt(0));
        $this->assertSame(999, $formatter->availableAt(-1));
    }

    public function testFutureIntegerDeadlinesRoundUpWithMutableDates(): void
    {
        Date::use(Carbon::class);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $formatter = new SupportInteractsWithTimeTestFixture;

        $this->assertSame(Carbon::class, Date::now()::class);
        $this->assertSame(1002, $formatter->availableAt(1));
        $this->assertSame(1000, $formatter->availableAt(0));
        $this->assertSame(999, $formatter->availableAt(-1));
    }

    public function testFutureIntervalDeadlinesRoundUpWithoutDelayingZeroOrInvertedIntervals(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $formatter = new SupportInteractsWithTimeTestFixture;
        $invertedInterval = new DateInterval('PT1S');
        $invertedInterval->invert = 1;

        $this->assertSame(1002, $formatter->availableAt(new DateInterval('PT1S')));
        $this->assertSame(1000, $formatter->availableAt(new DateInterval('PT0S')));
        $this->assertSame(999, $formatter->availableAt($invertedInterval));
    }

    public function testFutureAbsoluteDeadlinesRoundUpWhilePastAndWholeSecondValuesRemainExact(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $formatter = new SupportInteractsWithTimeTestFixture;

        $this->assertSame(1002, $formatter->availableAt(CarbonImmutable::createFromTimestampUTC('1001.100000')));
        $this->assertSame(999, $formatter->availableAt(CarbonImmutable::createFromTimestampUTC('999.900000')));
        $this->assertSame(1002, $formatter->availableAt(CarbonImmutable::createFromTimestampUTC('1002.000000')));
    }
}

class SupportInteractsWithTimeTestFixture
{
    use SupportInteractsWithTime {
        availableAt as public;
        runTimeForHumans as public;
    }
}
