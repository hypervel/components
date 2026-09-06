<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Support\days;
use function Hypervel\Support\hours;
use function Hypervel\Support\minutes;
use function Hypervel\Support\seconds;

class SupportIntervalFunctionsTest extends TestCase
{
    #[DataProvider('intervalProvider')]
    public function testIntervalsPreserveWholeAndFractionalUnits(
        callable $helper,
        int|float $amount,
        float $microseconds,
        array $fields,
    ): void {
        $interval = $helper($amount);

        $this->assertSame($microseconds, $interval->totalMicroseconds);
        $this->assertSame($fields, [$interval->d, $interval->h, $interval->i, $interval->s, $interval->microseconds]);
    }

    /**
     * Provide whole, fractional, and rounded interval values.
     */
    public static function intervalProvider(): array
    {
        return [
            'fractional seconds' => [seconds(...), 1.4, 1400000, [0, 0, 0, 1, 400000]],
            'fractional minutes' => [minutes(...), 1.4, 84000000, [0, 0, 1, 24, 0]],
            'fractional hours' => [hours(...), 1.4, 5040000000, [0, 1, 24, 0, 0]],
            'fractional days' => [days(...), 1.4, 120960000000, [1, 9, 36, 0, 0]],
            'negative seconds' => [seconds(...), -1.4, -1400000, [0, 0, 0, -1, -400000]],
            'negative minutes' => [minutes(...), -1.4, -84000000, [0, 0, -1, -24, 0]],
            'negative hours' => [hours(...), -1.4, -5040000000, [0, -1, -24, 0, 0]],
            'negative days' => [days(...), -1.4, -120960000000, [-1, -9, -36, 0, 0]],
            'tiny seconds' => [seconds(...), 0.000001, 1, [0, 0, 0, 0, 1]],
            'tiny minutes' => [minutes(...), 0.000001, 60, [0, 0, 0, 0, 60]],
            'tiny hours' => [hours(...), 0.000001, 3600, [0, 0, 0, 0, 3600]],
            'tiny days' => [days(...), 0.000001, 86400, [0, 0, 0, 0, 86400]],
            'integer seconds' => [seconds(...), 2, 2000000, [0, 0, 0, 2, 0]],
            'integer minutes' => [minutes(...), 2, 120000000, [0, 0, 2, 0, 0]],
            'integer hours' => [hours(...), 2, 7200000000, [0, 2, 0, 0, 0]],
            'integer days' => [days(...), 2, 172800000000, [2, 0, 0, 0, 0]],
            'rounded second' => [seconds(...), 0.9999999, 1000000, [0, 0, 0, 1, 0]],
            'rounded day' => [days(...), 1.9999999999999, 172800000000, [2, 0, 0, 0, 0]],
            'days remain days' => [days(...), 31.5, 2721600000000, [31, 12, 0, 0, 0]],
        ];
    }

    public function testFractionalUnitsAreIncludedInIntervalFormatting(): void
    {
        $this->assertSame('1 minute 24 seconds', minutes(1.4)->forHumans());
        $this->assertSame('P31DT12H', days(31.5)->spec());
    }

    #[DataProvider('calendarDayProvider')]
    public function testDayIntervalsPreserveCalendarArithmetic(float $amount, string $expected): void
    {
        $date = CarbonImmutable::parse('2026-03-06 20:00:00', 'America/New_York');

        $this->assertSame($expected, $date->add(days($amount))->format('Y-m-d H:i:sP'));
    }

    /**
     * Provide calendar-day intervals crossing daylight saving time.
     */
    public static function calendarDayProvider(): array
    {
        return [
            'fractional day' => [1.5, '2026-03-08 09:00:00-04:00'],
            'rounding carries into days' => [1.9999999999999, '2026-03-08 20:00:00-04:00'],
            'days do not cascade into months' => [31.5, '2026-04-07 08:00:00-04:00'],
        ];
    }
}
