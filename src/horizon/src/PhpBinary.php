<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Hypervel\Support\ProcessUtils;

class PhpBinary
{
    /**
     * Get the path to the PHP executable.
     */
    public static function path(): string
    {
        return ProcessUtils::escapeArgument(PHP_BINARY);
    }
}
