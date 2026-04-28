<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources;

class MissingValue implements PotentiallyMissing
{
    /**
     * Determine if the object should be considered "missing".
     */
    public function isMissing(): bool
    {
        return true;
    }
}
