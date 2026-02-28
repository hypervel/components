<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Broadcasting;

interface Factory
{
    /**
     * Get a broadcaster implementation by name.
     */
    public function connection(?string $name = null): Broadcaster;
}
