<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Cache;

use UnitEnum;

interface Factory
{
    /**
     * Get a cache store instance by name.
     */
    public function store(UnitEnum|string|null $name = null): Repository;
}
