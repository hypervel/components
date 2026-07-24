<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Carbon\CarbonImmutable as BaseCarbonImmutable;
use Hypervel\Support\Traits\DateHelpers;

/**
 * Carbon's immutable magic modifier metadata names its base class even though
 * these methods preserve subclasses at runtime.
 *
 * @method static addMicroseconds(int|float $value = 1)
 * @method static addMinute()
 * @method static addSecond()
 * @method static addSeconds(int|float $value = 1)
 * @method static ceilSeconds(float $precision = 1)
 * @method static subMinutes(int|float $value = 1)
 * @method static subSeconds(int|float $value = 1)
 */
class CarbonImmutable extends BaseCarbonImmutable
{
    use DateHelpers;

    /**
     * Convert the instance to a mutable date.
     */
    public function toMutable(): Carbon
    {
        return $this->cast(Carbon::class);
    }

    /**
     * Convert the instance to an immutable date.
     */
    public function toImmutable(): static
    {
        return $this;
    }
}
