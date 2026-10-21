<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Closure;
use DateTimeZone;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Hypervel\Support\CarbonImmutable;

readonly class CalendarWindow extends AdmissionPolicy
{
    /**
     * The local time at which a daily window resets by default.
     */
    public const string MIDNIGHT = '00:00:00';

    /**
     * The local time at which a daily window resets.
     */
    public string $at;

    /**
     * The timezone whose clock and calendar the window follows.
     */
    public string $timezone;

    /**
     * The resolved timezone.
     */
    private DateTimeZone $zone;

    /**
     * Create a new calendar-window limit.
     *
     * @param 'day'|'hour'|'minute'|'month' $period
     */
    public function __construct(
        public int $maxAttempts,
        public string $period,
        string $at = self::MIDNIGHT,
        ?string $timezone = null,
        string $key = '',
        int $cost = 1,
        bool $global = false,
        ?Closure $afterCallback = null,
        ?Closure $responseCallback = null,
    ) {
        static::ensurePositive('maximum attempts', $maxAttempts);

        if (! in_array($period, ['minute', 'hour', 'day', 'month'], true)) {
            throw new InvalidRateLimitException('The calendar window period must be minute, hour, day, or month.');
        }

        if (preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?\z/', $at) !== 1) {
            throw new InvalidRateLimitException('The calendar window reset time must use the 24-hour HH:MM or HH:MM:SS format.');
        }

        $this->at = strlen($at) === 5 ? $at . ':00' : $at;

        if ($period !== 'day' && $this->at !== self::MIDNIGHT) {
            throw new InvalidRateLimitException('Only daily calendar windows may reset at a time other than midnight.');
        }

        $this->zone = new DateTimeZone($timezone ?? date_default_timezone_get());
        $this->timezone = $this->zone->getName();

        parent::__construct($key, $cost, $global, $afterCallback, $responseCallback);
    }

    /**
     * Create a new limit that resets at the start of every minute.
     */
    public static function perMinute(int $maxAttempts): static
    {
        return new static($maxAttempts, 'minute');
    }

    /**
     * Create a new limit that resets at the start of every hour.
     */
    public static function perHour(int $maxAttempts): static
    {
        return new static($maxAttempts, 'hour');
    }

    /**
     * Create a new limit that resets every day at the given local time.
     */
    public static function perDay(int $maxAttempts, string $at = self::MIDNIGHT): static
    {
        return new static($maxAttempts, 'day', $at);
    }

    /**
     * Create a new limit that resets at the start of every month.
     */
    public static function perMonth(int $maxAttempts): static
    {
        return new static($maxAttempts, 'month');
    }

    /**
     * Set the timezone whose clock and calendar the window follows.
     */
    public function timezone(string $timezone): static
    {
        return new static(
            maxAttempts: $this->maxAttempts,
            period: $this->period,
            at: $this->at,
            timezone: $timezone,
            key: $this->key,
            cost: $this->cost,
            global: $this->global,
            afterCallback: $this->afterCallback,
            responseCallback: $this->responseCallback,
        );
    }

    /**
     * Get the start and end of the window containing the given time, in epoch microseconds.
     *
     * The end is always later than the given time.
     *
     * @return array{int, int}
     */
    public function window(int $nowMicroseconds): array
    {
        $now = intdiv($nowMicroseconds, 1_000_000);

        [$start, $end] = match ($this->period) {
            'minute' => $this->clockWindow($now, 60),
            'hour' => $this->clockWindow($now, 3_600),
            'day' => $this->dailyWindow($now),
            'month' => $this->monthlyWindow($now),
        };

        if ($end > intdiv(self::MAX_INTEGER, 1_000_000)) {
            throw new InvalidRateLimitException('The rate limiter timestamp exceeds the supported integer range.');
        }

        return [$start * 1_000_000, $end * 1_000_000];
    }

    /**
     * Create a copy with the given shared policy values.
     */
    protected function newInstance(
        string $key,
        int $cost,
        bool $global,
        ?Closure $afterCallback,
        ?Closure $responseCallback,
    ): static {
        return new static(
            maxAttempts: $this->maxAttempts,
            period: $this->period,
            at: $this->at,
            timezone: $this->timezone,
            key: $key,
            cost: $cost,
            global: $global,
            afterCallback: $afterCallback,
            responseCallback: $responseCallback,
        );
    }

    /**
     * Get the minute or hour window containing the given timestamp.
     *
     * @return array{int, int}
     */
    private function clockWindow(int $now, int $length): array
    {
        $offset = $this->offsetAt($now);
        $start = $now - ($now + $offset) % $length;
        $end = $start + $length;

        if ($this->offsetAt($start) === $offset && $this->offsetAt($end) === $offset) {
            return [$start, $end];
        }

        // An offset change that is not a whole number of windows, such as Lord Howe
        // Island's half hour, moves the local boundaries. Ending or starting the window
        // at the change keeps it from overlapping its neighbor.
        /** @var list<array{ts: int}> $transitions */
        $transitions = $this->zone->getTransitions($start, $end);

        foreach (array_slice($transitions, 1) as $transition) {
            if ($transition['ts'] <= $now) {
                $start = max($start, $transition['ts']);
            } else {
                $end = min($end, $transition['ts']);

                break;
            }
        }

        return [$start, $end];
    }

    /**
     * Get the daily window containing the given timestamp.
     *
     * @return array{int, int}
     */
    private function dailyWindow(int $now): array
    {
        $date = $this->localDate($now);
        $today = $this->resolveLocalTime($date);

        return $today <= $now
            ? [$today, $this->resolveLocalTime($date->addDay())]
            : [$this->resolveLocalTime($date->subDay()), $today];
    }

    /**
     * Get the monthly window containing the given timestamp.
     *
     * @return array{int, int}
     */
    private function monthlyWindow(int $now): array
    {
        $month = $this->localDate($now)->startOfMonth();

        return [$this->resolveLocalTime($month), $this->resolveLocalTime($month->addMonth())];
    }

    /**
     * Get the local calendar date of a timestamp as a UTC midnight.
     */
    private function localDate(int $timestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            CarbonImmutable::createFromTimestamp($timestamp, $this->zone)->format('Y-m-d'),
            'UTC',
        );
    }

    /**
     * Resolve the reset time on a local date to a timestamp.
     *
     * PHP's date parser picks a zone-dependent occurrence of a time repeated by a
     * clock change, so each possible offset is checked and the first occurrence wins.
     * A time skipped by a clock change moves forward by the change.
     */
    private function resolveLocalTime(CarbonImmutable $date): int
    {
        $wall = $date->setTimeFromTimeString($this->at)->getTimestamp();
        $before = $this->offsetAt($wall - 86_400);
        $instants = [];

        foreach ([$before, $this->offsetAt($wall + 86_400)] as $offset) {
            if ($this->offsetAt($wall - $offset) === $offset) {
                $instants[] = $wall - $offset;
            }
        }

        return $instants === [] ? $wall - $before : min($instants);
    }

    /**
     * Get the timezone's UTC offset in seconds at a timestamp.
     */
    private function offsetAt(int $timestamp): int
    {
        return $this->zone->getOffset(CarbonImmutable::createFromTimestamp($timestamp));
    }
}
