<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Contracts;

use UnitEnum;

interface Factory
{
    /**
     * Get a provider implementation.
     */
    public function driver(UnitEnum|string|null $driver = null): Provider;
}
