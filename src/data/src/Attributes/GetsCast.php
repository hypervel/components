<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Hypervel\Data\Casts\Cast;

interface GetsCast
{
    /**
     * Get the configured cast.
     */
    public function get(): Cast;
}
