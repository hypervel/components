<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Carbon\Carbon;
use Closure;
use DateTimeInterface;
use Hypervel\Foundation\Testing\Wormhole;

trait InteractsWithTime
{
    /**
     * Freeze time.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function freezeTime($callback = null)
    {
        $result = $this->travelTo($now = Carbon::now(), $callback);

        return $callback === null ? $now : $result;
    }

    /**
     * Freeze time at the beginning of the current second.
     *
     * @param null|callable $callback
     * @return mixed
     */
    public function freezeSecond($callback = null)
    {
        $result = $this->travelTo($now = Carbon::now()->startOfSecond(), $callback);

        return $callback === null ? $now : $result;
    }

    /**
     * Begin travelling to another time.
     */
    public function travel(int $value): Wormhole
    {
        return new Wormhole($value);
    }

    /**
     * Travel to another time.
     *
     * @param null|bool|\Carbon\Carbon|Closure|DateTimeInterface|string $date
     * @param null|callable $callback
     * @return mixed
     */
    public function travelTo($date, $callback = null)
    {
        Carbon::setTestNow($date);

        if ($callback) {
            return tap($callback($date), function () {
                Carbon::setTestNow();
            });
        }
    }

    /**
     * Travel back to the current time.
     *
     * @return DateTimeInterface
     */
    public function travelBack()
    {
        return Wormhole::back();
    }
}
