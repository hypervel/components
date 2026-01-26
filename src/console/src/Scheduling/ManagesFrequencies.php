<?php

declare(strict_types=1);

namespace Hypervel\Console\Scheduling;

use Closure;
use DateTimeZone;
use Hypervel\Support\Carbon;
use InvalidArgumentException;
use UnitEnum;

use function Hypervel\Support\enum_value;

trait ManagesFrequencies
{
    /**
     * The Cron expression representing the event's frequency.
     */
    public function cron(string $expression): static
    {
        $this->expression = $expression;

        return $this;
    }

    /**
     * Schedule the event to run between start and end time.
     */
    public function between(string $startTime, string $endTime): static
    {
        return $this->when($this->inTimeInterval($startTime, $endTime));
    }

    /**
     * Schedule the event to not run between start and end time.
     */
    public function unlessBetween(string $startTime, string $endTime): static
    {
        return $this->skip($this->inTimeInterval($startTime, $endTime));
    }

    /**
     * Schedule the event to run between start and end time.
     */
    private function inTimeInterval(string $startTime, string $endTime): Closure
    {
        [$now, $startTime, $endTime] = [
            Carbon::now($this->timezone),
            Carbon::parse($startTime, $this->timezone),
            Carbon::parse($endTime, $this->timezone),
        ];

        if ($endTime->lessThan($startTime)) {
            if ($startTime->greaterThan($now)) {
                $startTime = $startTime->subDay();
            } else {
                $endTime = $endTime->addDay();
            }
        }

        return fn () => $now->between($startTime, $endTime);
    }

    /**
     * Schedule the event to run every second.
     */
    public function everySecond(): static
    {
        return $this->repeatEvery(1);
    }

    /**
     * Schedule the event to run every two seconds.
     */
    public function everyTwoSeconds(): static
    {
        return $this->repeatEvery(2);
    }

    /**
     * Schedule the event to run every five seconds.
     */
    public function everyFiveSeconds(): static
    {
        return $this->repeatEvery(5);
    }

    /**
     * Schedule the event to run every ten seconds.
     */
    public function everyTenSeconds(): static
    {
        return $this->repeatEvery(10);
    }

    /**
     * Schedule the event to run every fifteen seconds.
     */
    public function everyFifteenSeconds(): static
    {
        return $this->repeatEvery(15);
    }

    /**
     * Schedule the event to run every twenty seconds.
     */
    public function everyTwentySeconds(): static
    {
        return $this->repeatEvery(20);
    }

    /**
     * Schedule the event to run every thirty seconds.
     */
    public function everyThirtySeconds(): static
    {
        return $this->repeatEvery(30);
    }

    /**
     * Schedule the event to run multiple times per minute.
     *
     * @param int<0, 59> $seconds
     */
    protected function repeatEvery(int $seconds): static
    {
        if (60 % $seconds !== 0) {
            throw new InvalidArgumentException("The seconds [{$seconds}] are not evenly divisible by 60.");
        }

        $this->repeatSeconds = $seconds;

        return $this->everyMinute();
    }

    /**
     * Schedule the event to run every minute.
     */
    public function everyMinute(): static
    {
        return $this->spliceIntoPosition(1, '*');
    }

    /**
     * Schedule the event to run every two minutes.
     */
    public function everyTwoMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/2');
    }

    /**
     * Schedule the event to run every three minutes.
     */
    public function everyThreeMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/3');
    }

    /**
     * Schedule the event to run every four minutes.
     */
    public function everyFourMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/4');
    }

    /**
     * Schedule the event to run every five minutes.
     */
    public function everyFiveMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/5');
    }

    /**
     * Schedule the event to run every ten minutes.
     */
    public function everyTenMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/10');
    }

    /**
     * Schedule the event to run every fifteen minutes.
     */
    public function everyFifteenMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/15');
    }

    /**
     * Schedule the event to run every thirty minutes.
     */
    public function everyThirtyMinutes(): static
    {
        return $this->spliceIntoPosition(1, '*/30');
    }

    /**
     * Schedule the event to run hourly.
     */
    public function hourly(): static
    {
        return $this->spliceIntoPosition(1, 0);
    }

    /**
     * Schedule the event to run hourly at a given offset in the hour.
     *
     * @param array|int<0, 59>|int<0, 59>[]|string $offset
     */
    public function hourlyAt(array|int|string $offset): static
    {
        return $this->hourBasedSchedule($offset, '*');
    }

    /**
     * Schedule the event to run every odd hour.
     */
    public function everyOddHour(array|int|string $offset = 0): static
    {
        return $this->hourBasedSchedule($offset, '1-23/2');
    }

    /**
     * Schedule the event to run every two hours.
     */
    public function everyTwoHours(array|int|string $offset = 0): static
    {
        return $this->hourBasedSchedule($offset, '*/2');
    }

    /**
     * Schedule the event to run every three hours.
     */
    public function everyThreeHours(array|int|string $offset = 0): static
    {
        return $this->hourBasedSchedule($offset, '*/3');
    }

    /**
     * Schedule the event to run every four hours.
     */
    public function everyFourHours(array|int|string $offset = 0): static
    {
        return $this->hourBasedSchedule($offset, '*/4');
    }

    /**
     * Schedule the event to run every six hours.
     */
    public function everySixHours(array|int|string $offset = 0): static
    {
        return $this->hourBasedSchedule($offset, '*/6');
    }

    /**
     * Schedule the event to run daily.
     */
    public function daily(): static
    {
        return $this->hourBasedSchedule(0, 0);
    }

    /**
     * Schedule the command at a given time.
     */
    public function at(string $time): static
    {
        return $this->dailyAt($time);
    }

    /**
     * Schedule the event to run daily at a given time (10:00, 19:30, etc).
     */
    public function dailyAt(string $time): static
    {
        $segments = explode(':', $time);

        return $this->hourBasedSchedule(
            count($segments) === 2 ? (int) $segments[1] : '0',
            (int) $segments[0]
        );
    }

    /**
     * Schedule the event to run twice daily.
     *
     * @param int<0, 23> $first
     * @param int<0, 23> $second
     */
    public function twiceDaily(int $first = 1, int $second = 13): static
    {
        return $this->twiceDailyAt($first, $second, 0);
    }

    /**
     * Schedule the event to run twice daily at a given offset.
     *
     * @param int<0, 23> $first
     * @param int<0, 23> $second
     * @param int<0, 59> $offset
     */
    public function twiceDailyAt(int $first = 1, int $second = 13, int $offset = 0): static
    {
        $hours = $first . ',' . $second;

        return $this->hourBasedSchedule($offset, $hours);
    }

    /**
     * Schedule the event to run at the given minutes and hours.
     *
     * @param array|int<0, 59>|string $minutes
     * @param array|int<0, 23>|string $hours
     */
    protected function hourBasedSchedule(array|int|string $minutes, array|int|string $hours)
    {
        $minutes = is_array($minutes) ? implode(',', $minutes) : $minutes;

        $hours = is_array($hours) ? implode(',', $hours) : $hours;

        return $this->spliceIntoPosition(1, $minutes)
            ->spliceIntoPosition(2, $hours);
    }

    /**
     * Schedule the event to run only on weekdays.
     */
    public function weekdays(): static
    {
        return $this->days(Schedule::MONDAY . '-' . Schedule::FRIDAY);
    }

    /**
     * Schedule the event to run only on weekends.
     */
    public function weekends(): static
    {
        return $this->days(Schedule::SATURDAY . ',' . Schedule::SUNDAY);
    }

    /**
     * Schedule the event to run only on Mondays.
     */
    public function mondays(): static
    {
        return $this->days(Schedule::MONDAY);
    }

    /**
     * Schedule the event to run only on Tuesdays.
     */
    public function tuesdays(): static
    {
        return $this->days(Schedule::TUESDAY);
    }

    /**
     * Schedule the event to run only on Wednesdays.
     */
    public function wednesdays(): static
    {
        return $this->days(Schedule::WEDNESDAY);
    }

    /**
     * Schedule the event to run only on Thursdays.
     */
    public function thursdays(): static
    {
        return $this->days(Schedule::THURSDAY);
    }

    /**
     * Schedule the event to run only on Fridays.
     */
    public function fridays(): static
    {
        return $this->days(Schedule::FRIDAY);
    }

    /**
     * Schedule the event to run only on Saturdays.
     */
    public function saturdays(): static
    {
        return $this->days(Schedule::SATURDAY);
    }

    /**
     * Schedule the event to run only on Sundays.
     */
    public function sundays(): static
    {
        return $this->days(Schedule::SUNDAY);
    }

    /**
     * Schedule the event to run weekly.
     */
    public function weekly(): static
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(5, 0);
    }

    /**
     * Schedule the event to run weekly on a given day and time.
     *
     * @param array|mixed $dayOfWeek
     */
    public function weeklyOn(mixed $dayOfWeek, string $time = '0:0'): static
    {
        $this->dailyAt($time);

        return $this->days($dayOfWeek);
    }

    /**
     * Schedule the event to run monthly.
     */
    public function monthly(): static
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, 1);
    }

    /**
     * Schedule the event to run monthly on a given day and time.
     *
     * @param int<1, 31> $dayOfMonth
     */
    public function monthlyOn(int $dayOfMonth = 1, string $time = '0:0'): static
    {
        $this->dailyAt($time);

        return $this->spliceIntoPosition(3, $dayOfMonth);
    }

    /**
     * Schedule the event to run twice monthly at a given time.
     *
     * @param int<1, 31> $first
     * @param int<1, 31> $second
     */
    public function twiceMonthly(int $first = 1, int $second = 16, string $time = '0:0'): static
    {
        $daysOfMonth = $first . ',' . $second;

        $this->dailyAt($time);

        return $this->spliceIntoPosition(3, $daysOfMonth);
    }

    /**
     * Schedule the event to run on the last day of the month.
     */
    public function lastDayOfMonth(string $time = '0:0'): static
    {
        $this->dailyAt($time);

        return $this->spliceIntoPosition(3, Carbon::now()->endOfMonth()->day);
    }

    /**
     * Schedule the event to run quarterly.
     */
    public function quarterly(): static
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, 1)
            ->spliceIntoPosition(4, '1-12/3');
    }

    /**
     * Schedule the event to run quarterly on a given day and time.
     */
    public function quarterlyOn(int $dayOfQuarter = 1, string $time = '0:0'): static
    {
        $this->dailyAt($time);

        return $this->spliceIntoPosition(3, $dayOfQuarter)
            ->spliceIntoPosition(4, '1-12/3');
    }

    /**
     * Schedule the event to run yearly.
     */
    public function yearly(): static
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, 1)
            ->spliceIntoPosition(4, 1);
    }

    /**
     * Schedule the event to run yearly on a given month, day, and time.
     */
    public function yearlyOn(int $month = 1, int|string $dayOfMonth = 1, string $time = '0:0'): static
    {
        $this->dailyAt($time);

        return $this->spliceIntoPosition(3, $dayOfMonth)
            ->spliceIntoPosition(4, $month);
    }

    /**
     * Set the days of the week the command should run on.
     *
     * @param array|mixed $days
     */
    public function days(mixed $days): static
    {
        $days = is_array($days) ? $days : func_get_args();

        return $this->spliceIntoPosition(5, implode(',', $days));
    }

    /**
     * Set the timezone the date should be evaluated on.
     */
    public function timezone(DateTimeZone|UnitEnum|string $timezone): static
    {
        $this->timezone = $timezone instanceof UnitEnum
            ? enum_value($timezone)
            : $timezone;

        return $this;
    }

    /**
     * Splice the given value into the given position of the expression.
     */
    protected function spliceIntoPosition(int $position, int|string $value): static
    {
        $segments = preg_split('/\s+/', $this->expression);

        $segments[$position - 1] = (string) $value;

        return $this->cron(implode(' ', $segments));
    }
}
