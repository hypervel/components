<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Carbon\CarbonInterface;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Date;

class Wormhole
{
    /**
     * The amount of time to travel.
     */
    public int $value;

    /**
     * Create a new wormhole instance.
     */
    public function __construct(int $value)
    {
        $this->value = $value;
    }

    /**
     * Travel forward the given number of microseconds.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function microsecond(?callable $callback = null): mixed
    {
        return $this->microseconds($callback);
    }

    /**
     * Travel forward the given number of microseconds.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function microseconds(?callable $callback = null): mixed
    {
        Carbon::setTestNow(Date::now()->addMicroseconds($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of milliseconds.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function millisecond(?callable $callback = null): mixed
    {
        return $this->milliseconds($callback);
    }

    /**
     * Travel forward the given number of milliseconds.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function milliseconds(?callable $callback = null): mixed
    {
        Carbon::setTestNow(Date::now()->addMilliseconds($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of seconds.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function second(?callable $callback = null): mixed
    {
        return $this->seconds($callback);
    }

    /**
     * Travel forward the given number of seconds.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function seconds(?callable $callback = null): mixed
    {
        Carbon::setTestNow(Date::now()->addSeconds($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of minutes.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function minute(?callable $callback = null): mixed
    {
        return $this->minutes($callback);
    }

    /**
     * Travel forward the given number of minutes.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function minutes(?callable $callback = null): mixed
    {
        Carbon::setTestNow(Date::now()->addMinutes($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of hours.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function hour(?callable $callback = null): mixed
    {
        return $this->hours($callback);
    }

    /**
     * Travel forward the given number of hours.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function hours(?callable $callback = null): mixed
    {
        Carbon::setTestNow(Date::now()->addHours($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of days.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function day(?callable $callback = null): mixed
    {
        return $this->days($callback);
    }

    /**
     * Travel forward the given number of days.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function days(?callable $callback = null): mixed
    {
        Carbon::setTestNow(Date::now()->addDays($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of weeks.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function week(?callable $callback = null): mixed
    {
        return $this->weeks($callback);
    }

    /**
     * Travel forward the given number of weeks.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function weeks(?callable $callback = null): mixed
    {
        Carbon::setTestNow(Date::now()->addWeeks($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of months.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function month(?callable $callback = null): mixed
    {
        return $this->months($callback);
    }

    /**
     * Travel forward the given number of months.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function months(?callable $callback = null): mixed
    {
        Carbon::setTestNow(Date::now()->addMonths($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of years.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function year(?callable $callback = null): mixed
    {
        return $this->years($callback);
    }

    /**
     * Travel forward the given number of years.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function years(?callable $callback = null): mixed
    {
        Carbon::setTestNow(Date::now()->addYears($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel back to the current time.
     */
    public static function back(): CarbonInterface
    {
        Carbon::setTestNow();

        return Date::now();
    }

    /**
     * Handle the given optional execution callback.
     *
     * @template TReturn
     *
     * @param null|(callable(): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    protected function handleCallback(?callable $callback): mixed
    {
        if ($callback) {
            try {
                return $callback();
            } finally {
                Carbon::setTestNow();
            }
        }

        return null;
    }
}
