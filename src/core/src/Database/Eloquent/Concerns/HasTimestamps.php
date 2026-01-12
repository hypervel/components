<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Carbon\CarbonInterface;
use Hypervel\Support\Facades\Date;

/**
 * Overrides Hyperf's HasTimestamps to use the Date facade.
 *
 * This allows applications to configure a custom date class (e.g., CarbonImmutable)
 * via Date::use() and have it respected throughout the framework.
 */
trait HasTimestamps
{
    /**
     * Get a fresh timestamp for the model.
     *
     * Uses the Date facade to respect any custom date class configured
     * via Date::use() (e.g., CarbonImmutable).
     */
    public function freshTimestamp(): CarbonInterface
    {
        return Date::now();
    }
}
