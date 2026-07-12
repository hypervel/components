<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Filesystem;

use UnitEnum;

interface Factory
{
    /**
     * Get a filesystem implementation.
     */
    public function disk(UnitEnum|string|null $name = null): Filesystem;
}
