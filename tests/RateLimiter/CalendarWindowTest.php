<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use DateInvalidTimeZoneException;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\CalendarWindow;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CalendarWindowTest extends TestCase
{
    public function testFactoriesCreateCalendarWindowPolicies(): void
    {
        $this->assertPolicy(CalendarWindow::perMinute(60), 60, 'minute', '00:00:00');
        $this->assertPolicy(CalendarWindow::perHour(1_000), 1_000, 'hour', '00:00:00');
        $this->assertPolicy(CalendarWindow::perDay(10_000), 10_000, 'day', '00:00:00');
        $this->assertPolicy(CalendarWindow::perDay(10_000, at: '01:30'), 10_000, 'day', '01:30:00');
        $this->assertPolicy(CalendarWindow::perDay(10_000, at: '01:30:15'), 10_000, 'day', '01:30:15');
        $this->assertPolicy(CalendarWindow::perMonth(100_000), 100_000, 'month', '00:00:00');
    }

    public function testTheTimezoneDefaultsToTheApplicationTimezoneAndMayBeChanged(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Kolkata');

        try {
            $original = CalendarWindow::perDay(10);
        } finally {
            date_default_timezone_set($previousTimezone);
        }

        $changed = $original->timezone('America/New_York');

        $this->assertNotSame($original, $changed);
        $this->assertSame('Asia/Kolkata', $original->timezone);
        $this->assertSame('America/New_York', $changed->timezone);
    }

    public function testFluentModifiersReturnImmutableCopiesThatKeepTheCalendarOptions(): void
    {
        $after = static fn (): bool => true;
        $response = static fn (): string => 'limited';
        $original = CalendarWindow::perDay(10, at: '01:00')->timezone('Europe/Berlin');
        $modified = $original
            ->by('api')
            ->cost(3)
            ->globally()
            ->after($after)
            ->response($response)
            ->timezone('Australia/Sydney');

        $this->assertNotSame($original, $modified);
        $this->assertSame('', $original->key);
        $this->assertSame('Europe/Berlin', $original->timezone);

        $this->assertSame(10, $modified->maxAttempts);
        $this->assertSame('day', $modified->period);
        $this->assertSame('01:00:00', $modified->at);
        $this->assertSame('Australia/Sydney', $modified->timezone);
        $this->assertSame('api', $modified->key);
        $this->assertSame(3, $modified->cost);
        $this->assertTrue($modified->global);
        $this->assertSame($after, $modified->afterCallback);
        $this->assertSame($response, $modified->responseCallback);
    }

    public function testInvalidValuesAreRejected(): void
    {
        foreach ([
            static fn (): CalendarWindow => CalendarWindow::perMinute(0),
            static fn (): CalendarWindow => CalendarWindow::perMinute(1)->cost(0),
            static fn (): CalendarWindow => new CalendarWindow(1, 'week'),
            static fn (): CalendarWindow => CalendarWindow::perDay(1, at: '24:00'),
            static fn (): CalendarWindow => CalendarWindow::perDay(1, at: '1:00'),
            static fn (): CalendarWindow => CalendarWindow::perDay(1, at: '01:60'),
            static fn (): CalendarWindow => CalendarWindow::perDay(1, at: '01:00:60'),
            static fn (): CalendarWindow => CalendarWindow::perDay(1, at: '1am'),
            static fn (): CalendarWindow => new CalendarWindow(1, 'month', '01:00'),
        ] as $callback) {
            try {
                $callback();
                $this->fail('Expected an invalid rate limit exception.');
            } catch (InvalidRateLimitException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInvalidTimezonesAreRejected(): void
    {
        $this->expectException(DateInvalidTimeZoneException::class);

        CalendarWindow::perDay(1)->timezone('Not/A_Zone');
    }

    /**
     * @param callable(): CalendarWindow $policy
     */
    #[DataProvider('windowProvider')]
    public function testWindowsFollowTheLocalClockAndCalendar(
        callable $policy,
        string $now,
        string $start,
        string $end,
    ): void {
        $window = $policy()->window((int) CarbonImmutable::parse($now . ' UTC')->getPreciseTimestamp(6));

        $this->assertSame(
            [
                CarbonImmutable::parse($start . ' UTC')->getTimestamp() * 1_000_000,
                CarbonImmutable::parse($end . ' UTC')->getTimestamp() * 1_000_000,
            ],
            $window,
        );
    }

    /**
     * Get UTC times and the UTC start and end of the window containing them.
     */
    public static function windowProvider(): array
    {
        $minute = static fn (): CalendarWindow => CalendarWindow::perMinute(1)->timezone('UTC');
        $kolkataHour = static fn (): CalendarWindow => CalendarWindow::perHour(1)->timezone('Asia/Kolkata');
        $newYorkHour = static fn (): CalendarWindow => CalendarWindow::perHour(1)->timezone('America/New_York');
        $berlinHour = static fn (): CalendarWindow => CalendarWindow::perHour(1)->timezone('Europe/Berlin');
        $lordHoweHour = static fn (): CalendarWindow => CalendarWindow::perHour(1)->timezone('Australia/Lord_Howe');
        $utcDay = static fn (): CalendarWindow => CalendarWindow::perDay(1)->timezone('UTC');
        $utcDayAtOne = static fn (): CalendarWindow => CalendarWindow::perDay(1, at: '01:00')->timezone('UTC');
        $newYorkDay = static fn (): CalendarWindow => CalendarWindow::perDay(1)->timezone('America/New_York');
        $newYorkDayAtRepeated = static fn (): CalendarWindow => CalendarWindow::perDay(1, at: '01:30')
            ->timezone('America/New_York');
        $newYorkDayAtSkipped = static fn (): CalendarWindow => CalendarWindow::perDay(1, at: '02:30')
            ->timezone('America/New_York');
        $berlinDayAtRepeated = static fn (): CalendarWindow => CalendarWindow::perDay(1, at: '02:30')
            ->timezone('Europe/Berlin');
        $sydneyDayAtRepeated = static fn (): CalendarWindow => CalendarWindow::perDay(1, at: '02:30')
            ->timezone('Australia/Sydney');
        $utcMonth = static fn (): CalendarWindow => CalendarWindow::perMonth(1)->timezone('UTC');
        $newYorkMonth = static fn (): CalendarWindow => CalendarWindow::perMonth(1)->timezone('America/New_York');

        return [
            'minute' => [$minute, '2026-10-07 06:45:30', '2026-10-07 06:45:00', '2026-10-07 06:46:00'],
            'minute just before its end' => [$minute, '2026-10-07 06:45:59.999999', '2026-10-07 06:45:00', '2026-10-07 06:46:00'],
            'minute at its start' => [$minute, '2026-10-07 06:46:00', '2026-10-07 06:46:00', '2026-10-07 06:47:00'],
            'half-hour offset' => [$kolkataHour, '2026-10-07 06:45:00', '2026-10-07 06:30:00', '2026-10-07 07:30:00'],

            // New York's clocks go back from 02:00 EDT to 01:00 EST at 06:00 UTC, so 01:00 local starts twice.
            'first 01:00 hour starts' => [$newYorkHour, '2026-11-01 05:00:00', '2026-11-01 05:00:00', '2026-11-01 06:00:00'],
            'first 01:00 hour ends' => [$newYorkHour, '2026-11-01 05:59:59', '2026-11-01 05:00:00', '2026-11-01 06:00:00'],
            'repeated 01:00 hour starts' => [$newYorkHour, '2026-11-01 06:00:00', '2026-11-01 06:00:00', '2026-11-01 07:00:00'],
            'repeated 01:00 hour' => [$newYorkHour, '2026-11-01 06:30:00', '2026-11-01 06:00:00', '2026-11-01 07:00:00'],

            // New York's clocks go forward from 02:00 EST to 03:00 EDT at 07:00 UTC.
            'hour before skipped hour' => [$newYorkHour, '2026-03-08 06:59:59', '2026-03-08 06:00:00', '2026-03-08 07:00:00'],
            'hour after skipped hour' => [$newYorkHour, '2026-03-08 07:00:00', '2026-03-08 07:00:00', '2026-03-08 08:00:00'],
            'Berlin first 02:00 hour' => [$berlinHour, '2026-10-25 00:59:59', '2026-10-25 00:00:00', '2026-10-25 01:00:00'],
            'Berlin repeated 02:00 hour' => [$berlinHour, '2026-10-25 01:00:00', '2026-10-25 01:00:00', '2026-10-25 02:00:00'],

            // Lord Howe Island's clocks go back half an hour at 15:00 UTC and forward half an hour at 15:30 UTC.
            'Lord Howe hour before going back' => [$lordHoweHour, '2026-04-04 14:59:59', '2026-04-04 14:00:00', '2026-04-04 15:00:00'],
            'Lord Howe half hour after going back' => [$lordHoweHour, '2026-04-04 15:00:00', '2026-04-04 15:00:00', '2026-04-04 15:30:00'],
            'Lord Howe hour after going back' => [$lordHoweHour, '2026-04-04 15:30:00', '2026-04-04 15:30:00', '2026-04-04 16:30:00'],
            'Lord Howe hour before going forward' => [$lordHoweHour, '2026-10-03 15:29:59', '2026-10-03 14:30:00', '2026-10-03 15:30:00'],
            'Lord Howe half hour after going forward' => [$lordHoweHour, '2026-10-03 15:30:00', '2026-10-03 15:30:00', '2026-10-03 16:00:00'],
            'Lord Howe hour after going forward' => [$lordHoweHour, '2026-10-03 16:00:00', '2026-10-03 16:00:00', '2026-10-03 17:00:00'],

            'day' => [$utcDay, '2026-10-07 12:00:00', '2026-10-07 00:00:00', '2026-10-08 00:00:00'],
            'day at midnight' => [$utcDay, '2026-10-08 00:00:00', '2026-10-08 00:00:00', '2026-10-09 00:00:00'],
            'day before its reset time' => [$utcDayAtOne, '2026-10-07 00:30:00', '2026-10-06 01:00:00', '2026-10-07 01:00:00'],
            'day at its reset time' => [$utcDayAtOne, '2026-10-07 01:00:00', '2026-10-07 01:00:00', '2026-10-08 01:00:00'],
            '23-hour day' => [$newYorkDay, '2026-03-08 12:00:00', '2026-03-08 05:00:00', '2026-03-09 04:00:00'],

            // A reset time repeated by a clock change resets at its first occurrence only.
            'New York before repeated reset' => [$newYorkDayAtRepeated, '2026-11-01 05:15:00', '2026-10-31 05:30:00', '2026-11-01 05:30:00'],
            'New York after first reset' => [$newYorkDayAtRepeated, '2026-11-01 05:45:00', '2026-11-01 05:30:00', '2026-11-02 06:30:00'],
            'New York during repeated reset' => [$newYorkDayAtRepeated, '2026-11-01 06:45:00', '2026-11-01 05:30:00', '2026-11-02 06:30:00'],
            'Berlin before repeated reset' => [$berlinDayAtRepeated, '2026-10-25 00:15:00', '2026-10-24 00:30:00', '2026-10-25 00:30:00'],
            'Berlin during repeated reset' => [$berlinDayAtRepeated, '2026-10-25 01:45:00', '2026-10-25 00:30:00', '2026-10-26 01:30:00'],
            'Sydney during repeated reset' => [$sydneyDayAtRepeated, '2026-04-04 16:45:00', '2026-04-04 15:30:00', '2026-04-05 16:30:00'],

            // A reset time skipped by a clock change moves forward that day, then returns to the configured time.
            'before skipped reset' => [$newYorkDayAtSkipped, '2026-03-08 07:00:00', '2026-03-07 07:30:00', '2026-03-08 07:30:00'],
            'after skipped reset' => [$newYorkDayAtSkipped, '2026-03-08 08:00:00', '2026-03-08 07:30:00', '2026-03-09 06:30:00'],

            'month' => [$utcMonth, '2026-01-31 12:00:00', '2026-01-01 00:00:00', '2026-02-01 00:00:00'],
            'month at year end' => [$utcMonth, '2026-12-15 12:00:00', '2026-12-01 00:00:00', '2027-01-01 00:00:00'],
            'month across a clock change' => [$newYorkMonth, '2026-11-15 12:00:00', '2026-11-01 04:00:00', '2026-12-01 05:00:00'],
        ];
    }

    public function testWindowsEndingBeyondTheSupportedRangeAreRejected(): void
    {
        $this->expectException(InvalidRateLimitException::class);
        $this->expectExceptionMessageIs('The rate limiter timestamp exceeds the supported integer range.');

        CalendarWindow::perMonth(1)->timezone('UTC')->window(AdmissionPolicy::MAX_INTEGER - 1);
    }

    /**
     * Assert the policy's capacity, period and reset time.
     */
    private function assertPolicy(CalendarWindow $policy, int $maxAttempts, string $period, string $at): void
    {
        $this->assertSame($maxAttempts, $policy->maxAttempts);
        $this->assertSame($period, $policy->period);
        $this->assertSame($at, $policy->at);
    }
}
