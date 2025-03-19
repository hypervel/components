<?php

declare(strict_types=1);

namespace Hypervel\Queue\Contracts;

interface Factory
{
    /**
     * Resolve a queue connection instance.
     */
    public function connection(?string $name = null): Queue;
}
