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
     * @template TReturn
     *
     * @param null|(callable(CarbonInterface): TReturn) $callback
     * @return ($callback is null ? CarbonInterface : TReturn)
     */
    public function freezeTime(?callable $callback = null): mixed
    {
        $result = $this->travelTo($now = Date::now(), $callback);

        return $callback === null ? $now : $result;
    }

    /**
     * Freeze time at the beginning of the current second.
     *
     * @template TReturn
     *
     * @param null|(callable(CarbonInterface): TReturn) $callback
     * @return ($callback is null ? CarbonInterface : TReturn)
     */
    public function freezeSecond(?callable $callback = null): mixed
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
     * @template TReturn
     * @template TDate of DateTimeInterface|Closure|string|bool|null
     *
     * @param TDate $date
     * @param null|(callable(TDate): TReturn) $callback
     * @return ($callback is null ? null : TReturn)
     */
    public function travelTo(DateTimeInterface|Closure|string|bool|null $date, ?callable $callback = null): mixed
    {
        Carbon::setTestNow($date);

        if ($callback) {
            try {
                return $callback($date);
            } finally {
                Carbon::setTestNow();
            }
        }

        return null;
    }

    /**
     * Travel back to the current time.
     */
    public function travelBack(): CarbonInterface
    {
        return Wormhole::back();
    }
}
