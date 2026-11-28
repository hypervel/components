<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Stringable;

/**
 * ProcessUtils is a bunch of utility methods.
 *
 * This class was originally copied from Symfony 3.
 */
class ProcessUtils
{
    /**
     * Escapes a string to be used as a shell argument.
     *
     * @param bool|float|int|string|Stringable $argument
     * @return string
     */
    public static function escapeArgument($argument)
    {
        return "'" . str_replace("'", "'\\''", (string) $argument) . "'";
    }

    /**
     * Is the given string surrounded by the given character?
     *
     * @param string $arg
     * @param string $char
     * @return bool
     */
    protected static function isSurroundedBy($arg, $char)
    {
        return strlen($arg) > 2 && $char === $arg[0] && $char === $arg[strlen($arg) - 1];
    }
}
