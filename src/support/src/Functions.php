<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use DateTimeZone;
use Hypervel\Support\Facades\Date;
use Symfony\Component\Process\PhpExecutableFinder;
use UnitEnum;

/**
 * Determine the PHP Binary.
 */
function php_binary(): string
{
    return (new PhpExecutableFinder())->find(false) ?: 'php';
}

/**
 * Determine the proper Artisan executable.
 */
function artisan_binary(): string
{
    return defined('ARTISAN_BINARY') ? ARTISAN_BINARY : 'artisan';
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
 * Create a new Carbon instance for the current time.
 *
 * @return \Hypervel\Support\Carbon
 */
function now(DateTimeZone|UnitEnum|string|null $tz = null): CarbonInterface
{
    return Date::now(enum_value($tz));
}

/**
 * Get the current date / time plus the given number of microseconds.
 */
function microseconds(int|float $microseconds): CarbonInterval
{
    return CarbonInterval::microseconds($microseconds);
}

/**
 * Get the current date / time plus the given number of milliseconds.
 */
function milliseconds(int|float $milliseconds): CarbonInterval
{
    return CarbonInterval::milliseconds($milliseconds);
}

/**
 * Get the current date / time plus the given number of seconds.
 */
function seconds(int|float $seconds): CarbonInterval
{
    return CarbonInterval::seconds($seconds);
}

/**
 * Get the current date / time plus the given number of minutes.
 */
function minutes(int|float $minutes): CarbonInterval
{
    return CarbonInterval::minutes($minutes);
}

/**
 * Get the current date / time plus the given number of hours.
 */
function hours(int|float $hours): CarbonInterval
{
    return CarbonInterval::hours($hours);
}

/**
 * Get the current date / time plus the given number of days.
 */
function days(int|float $days): CarbonInterval
{
    return CarbonInterval::days($days);
}

/**
 * Get the current date / time plus the given number of weeks.
 */
function weeks(int $weeks): CarbonInterval
{
    return CarbonInterval::weeks($weeks);
}

/**
 * Get the current date / time plus the given number of months.
 */
function months(int $months): CarbonInterval
{
    return CarbonInterval::months($months);
}

/**
 * Get the current date / time plus the given number of years.
 */
function years(int $years): CarbonInterval
{
    return CarbonInterval::years($years);
}
