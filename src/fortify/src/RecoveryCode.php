<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use Hypervel\Support\Str;

class RecoveryCode
{
    /**
     * Generate a new recovery code.
     */
    public static function generate(): string
    {
        return Str::random(10) . '-' . Str::random(10);
    }
}
