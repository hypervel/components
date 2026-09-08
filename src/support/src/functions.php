<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use DateTimeZone;
use Hypervel\Container\Container;
use Hypervel\Support\Defer\DeferredCallback;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Support\Facades\Date;
use Symfony\Component\Process\PhpExecutableFinder;
use UnitEnum;

/**
 * Determine the PHP Binary.
 */
function php_binary(): string
{
    return (new PhpExecutableFinder)->find(false) ?: 'php';
}

/**
 * Determine the proper Artisan executable.
 */
function artisan_binary(): string
{
    return defined('ARTISAN_BINARY') ? ARTISAN_BINARY : 'artisan';
}

/**
 * Defer execution of the given callback.
 *
 * @return ($callback is null ? DeferredCallbackCollection : DeferredCallback)
 */
function defer(?callable $callback = null, ?string $name = null, bool $always = false): DeferredCallback|DeferredCallbackCollection
{
    $callbacks = Container::getInstance()->make(DeferredCallbackCollection::class);

    if ($callback === null) {
        return $callbacks;
    }

    $deferred = new DeferredCallback($callback, $name, $always);
    $callbacks[] = $deferred;

    return $deferred;
}

/**
 * Get the Swoole hook flags for coroutine support.
 */
function swoole_hook_flags(): int
{
    return defined('SWOOLE_HOOK_FLAGS') ? SWOOLE_HOOK_FLAGS : SWOOLE_HOOK_ALL;
}

// Time functions...

/**
 * Create a new configured Carbon instance for the current time.
 */
function now(DateTimeZone|UnitEnum|string|null $tz = null): CarbonInterface
{
    return Date::now(enum_value($tz));
}

/**
 * Create an interval of the given number of microseconds.
 */
function microseconds(int|float $microseconds): CarbonInterval
{
    return CarbonInterval::microseconds($microseconds);
}

/**
 * Create an interval of the given number of milliseconds.
 */
function milliseconds(int|float $milliseconds): CarbonInterval
{
    return CarbonInterval::milliseconds($milliseconds);
}

/**
 * Create an interval of the given number of seconds.
 */
function seconds(int|float $seconds): CarbonInterval
{
    if (is_int($seconds)) {
        return CarbonInterval::seconds($seconds);
    }

    $whole = $seconds < 0 ? ceil($seconds) : floor($seconds);
    $microseconds = (int) round(($seconds - $whole) * CarbonInterface::MICROSECONDS_PER_SECOND);

    // A rounded fraction can reach a full second, which must not stay in the microsecond field.
    return CarbonInterval::seconds($whole + intdiv($microseconds, CarbonInterface::MICROSECONDS_PER_SECOND))
        ->microseconds($microseconds % CarbonInterface::MICROSECONDS_PER_SECOND);
}

/**
 * Create an interval of the given number of minutes.
 */
function minutes(int|float $minutes): CarbonInterval
{
    if (is_int($minutes)) {
        return CarbonInterval::minutes($minutes);
    }

    $microsecondsPerMinute = CarbonInterface::MICROSECONDS_PER_SECOND * CarbonInterface::SECONDS_PER_MINUTE;
    $whole = $minutes < 0 ? ceil($minutes) : floor($minutes);
    $microseconds = (int) round(($minutes - $whole) * $microsecondsPerMinute);
    $remainder = $microseconds % $microsecondsPerMinute;

    return CarbonInterval::minutes($whole + intdiv($microseconds, $microsecondsPerMinute))
        ->seconds(intdiv($remainder, CarbonInterface::MICROSECONDS_PER_SECOND))
        ->microseconds($remainder % CarbonInterface::MICROSECONDS_PER_SECOND);
}

/**
 * Create an interval of the given number of hours.
 */
function hours(int|float $hours): CarbonInterval
{
    if (is_int($hours)) {
        return CarbonInterval::hours($hours);
    }

    $microsecondsPerMinute = CarbonInterface::MICROSECONDS_PER_SECOND * CarbonInterface::SECONDS_PER_MINUTE;
    $microsecondsPerHour = $microsecondsPerMinute * CarbonInterface::MINUTES_PER_HOUR;
    $whole = $hours < 0 ? ceil($hours) : floor($hours);
    $microseconds = (int) round(($hours - $whole) * $microsecondsPerHour);
    $remainder = $microseconds % $microsecondsPerHour;

    return CarbonInterval::hours($whole + intdiv($microseconds, $microsecondsPerHour))
        ->minutes(intdiv($remainder, $microsecondsPerMinute))
        ->seconds(intdiv($remainder % $microsecondsPerMinute, CarbonInterface::MICROSECONDS_PER_SECOND))
        ->microseconds($remainder % CarbonInterface::MICROSECONDS_PER_SECOND);
}

/**
 * Create an interval of the given number of days.
 */
function days(int|float $days): CarbonInterval
{
    if (is_int($days)) {
        return CarbonInterval::days($days);
    }

    $microsecondsPerMinute = CarbonInterface::MICROSECONDS_PER_SECOND * CarbonInterface::SECONDS_PER_MINUTE;
    $microsecondsPerHour = $microsecondsPerMinute * CarbonInterface::MINUTES_PER_HOUR;
    $microsecondsPerDay = $microsecondsPerHour * CarbonInterface::HOURS_PER_DAY;
    $whole = $days < 0 ? ceil($days) : floor($days);
    $microseconds = (int) round(($days - $whole) * $microsecondsPerDay);
    $remainder = $microseconds % $microsecondsPerDay;

    // Keep whole calendar days intact; cascading can turn them into months.
    return CarbonInterval::days($whole + intdiv($microseconds, $microsecondsPerDay))
        ->hours(intdiv($remainder, $microsecondsPerHour))
        ->minutes(intdiv($remainder % $microsecondsPerHour, $microsecondsPerMinute))
        ->seconds(intdiv($remainder % $microsecondsPerMinute, CarbonInterface::MICROSECONDS_PER_SECOND))
        ->microseconds($remainder % CarbonInterface::MICROSECONDS_PER_SECOND);
}

/**
 * Create an interval of the given number of weeks.
 */
function weeks(int $weeks): CarbonInterval
{
    return CarbonInterval::weeks($weeks);
}

/**
 * Create an interval of the given number of months.
 */
function months(int $months): CarbonInterval
{
    return CarbonInterval::months($months);
}

/**
 * Create an interval of the given number of years.
 */
function years(int $years): CarbonInterval
{
    return CarbonInterval::years($years);
}
