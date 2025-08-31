<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Contracts;

interface Factory
{
    /**
     * Get an OAuth provider implementation.
     *
     * @return Provider $driver
     */
    public function driver(?string $driver = null): mixed;
}
