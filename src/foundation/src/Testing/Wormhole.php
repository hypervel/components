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
     * @param null|callable $callback
     * @return mixed
     */
    public function microsecond($callback = null)
    {
        return $this->microseconds($callback);
    }

    /**
     * Travel forward the given number of microseconds.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function microseconds($callback = null)
    {
        Carbon::setTestNow(Date::now()->addMicroseconds($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of milliseconds.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function millisecond($callback = null)
    {
        return $this->milliseconds($callback);
    }

    /**
     * Travel forward the given number of milliseconds.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function milliseconds($callback = null)
    {
        Carbon::setTestNow(Date::now()->addMilliseconds($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of seconds.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function second($callback = null)
    {
        return $this->seconds($callback);
    }

    /**
     * Travel forward the given number of seconds.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function seconds($callback = null)
    {
        Carbon::setTestNow(Date::now()->addSeconds($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of minutes.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function minute($callback = null)
    {
        return $this->minutes($callback);
    }

    /**
     * Travel forward the given number of minutes.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function minutes($callback = null)
    {
        Carbon::setTestNow(Date::now()->addMinutes($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of hours.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function hour($callback = null)
    {
        return $this->hours($callback);
    }

    /**
     * Travel forward the given number of hours.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function hours($callback = null)
    {
        Carbon::setTestNow(Date::now()->addHours($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of days.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function day($callback = null)
    {
        return $this->days($callback);
    }

    /**
     * Travel forward the given number of days.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function days($callback = null)
    {
        Carbon::setTestNow(Date::now()->addDays($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of weeks.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function week($callback = null)
    {
        return $this->weeks($callback);
    }

    /**
     * Travel forward the given number of weeks.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function weeks($callback = null)
    {
        Carbon::setTestNow(Date::now()->addWeeks($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of months.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function month($callback = null)
    {
        return $this->months($callback);
    }

    /**
     * Travel forward the given number of months.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function months($callback = null)
    {
        Carbon::setTestNow(Date::now()->addMonths($this->value));

        return $this->handleCallback($callback);
    }

    /**
     * Travel forward the given number of years.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function year($callback = null)
    {
        return $this->years($callback);
    }

    /**
     * Travel forward the given number of years.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function years($callback = null)
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
     * @param null|callable $callback
     * @return mixed
     */
    protected function handleCallback($callback)
    {
        if ($callback) {
            try {
                return $callback();
            } finally {
                Carbon::setTestNow();
            }
        }
    }
}
