<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Queue;

use UnitEnum;

interface Factory
{
    /**
     * Resolve a queue connection instance.
     */
    public function connection(UnitEnum|string|null $name = null): Queue;
}
