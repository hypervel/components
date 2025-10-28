<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

class PhpBinary
{
    /**
     * Get the path to the PHP executable.
     */
    public static function path(): string
    {
        $escape = '\\' === DIRECTORY_SEPARATOR ? '"' : '\'';

        return $escape . PHP_BINARY . $escape;
    }
}
