<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Contracts;

interface Factory
{
    /**
     * Get a provider implementation.
     */
    public function driver(?string $driver = null): mixed;
}
