<?php

declare(strict_types=1);

namespace Hypervel\Queue;

class MemoryLimit
{
    /**
     * Convert megabytes or a percentage of PHP's memory limit to megabytes.
     */
    public static function toMegabytes(float|string $limit): float
    {
        return str_ends_with((string) $limit, '%')
            ? ini_parse_quantity(ini_get('memory_limit')) / 1024 / 1024 * ((float) $limit / 100)
            : (float) $limit;
    }
}
