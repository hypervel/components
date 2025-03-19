<?php

declare(strict_types=1);

namespace Hypervel\Session\Contracts;

interface Factory
{
    /**
     * Get a session store instance by name.
     */
    public function store(?string $name = null): Session;
}
