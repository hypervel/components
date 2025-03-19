<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

class Key
{
    public const UP = "\e[A";

    public const SHIFT_UP = "\e[1;2A";

    public const DOWN = "\e[B";

    public const SHIFT_DOWN = "\e[1;2B";

    public const RIGHT = "\e[C";

    public const LEFT = "\e[D";

    public const UP_ARROW = "\eOA";

    public const DOWN_ARROW = "\eOB";

    public const RIGHT_ARROW = "\eOC";

    public const LEFT_ARROW = "\eOD";

    public const ESCAPE = "\e";

    public const DELETE = "\e[3~";

    public const BACKSPACE = "\177";

    public const ENTER = "\n";

    public const SPACE = ' ';

    public const TAB = "\t";

    public const SHIFT_TAB = "\e[Z";

    public const HOME = ["\e[1~", "\eOH", "\e[H", "\e[7~"];

    public const END = ["\e[4~", "\eOF", "\e[F", "\e[8~"];

    /**
     * Cancel/SIGINT.
     */
    public const CTRL_C = "\x03";

    /**
     * Previous/Up.
     */
    public const CTRL_P = "\x10";

    /**
     * Next/Down.
     */
    public const CTRL_N = "\x0E";

    /**
     * Forward/Right.
     */
    public const CTRL_F = "\x06";

    /**
     * Back/Left.
     */
    public const CTRL_B = "\x02";

    /**
     * Backspace.
     */
    public const CTRL_H = "\x08";

    /**
     * Home.
     */
    public const CTRL_A = "\x01";

    /**
     * EOF.
     */
    public const CTRL_D = "\x04";

    /**
     * End.
     */
    public const CTRL_E = "\x05";

    /**
     * Negative affirmation.
     */
    public const CTRL_U = "\x15";

    /**
     * Checks for the constant values for the given match and returns the match.
     *
     * @param array<array<string>|string> $keys
     */
    public static function oneOf(array $keys, string $match): ?string
    {
        foreach ($keys as $key) {
            if (is_array($key) && static::oneOf($key, $match) !== null) {
                return $match;
            }
            if ($key === $match) {
                return $match;
            }
        }

        return null;
    }
}
