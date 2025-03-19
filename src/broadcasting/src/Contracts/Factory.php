<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Contracts;

interface Factory
{
    /**
     * Get a broadcaster implementation by name.
     */
    public function connection(?string $name = null): Broadcaster;
}
