<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Hypervel\Foundation\Testing\Wormhole;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Date;

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
        $result = $this->travelTo($now = Date::now(), $callback);

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
        $result = $this->travelTo($now = Date::now()->startOfSecond(), $callback);

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
     * @param null|bool|CarbonInterface|Closure|DateTimeInterface|string $date
     * @param null|callable $callback
     * @return mixed
     */
    public function travelTo($date, $callback = null)
    {
        Carbon::setTestNow($date);

        if ($callback) {
            try {
                return $callback($date);
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    /**
     * Travel back to the current time.
     */
    public function travelBack(): CarbonInterface
    {
        return Wormhole::back();
    }
}
