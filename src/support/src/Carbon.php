<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Carbon\Carbon as BaseCarbon;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Support\Traits\DateHelpers;

class Carbon extends BaseCarbon implements Transient
{
    use DateHelpers;

    /**
     * Convert the instance to a mutable date.
     */
    public function toMutable(): static
    {
        return $this->cast(static::class);
    }

    /**
     * Convert the instance to an immutable date.
     */
    public function toImmutable(): CarbonImmutable
    {
        return $this->cast(CarbonImmutable::class);
    }
}
