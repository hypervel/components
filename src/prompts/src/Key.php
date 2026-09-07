<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

class Key
{
    public const string UP = "\e[A";

    public const string SHIFT_UP = "\e[1;2A";

    public const string PAGE_UP = "\e[5~";

    public const string DOWN = "\e[B";

    public const string SHIFT_DOWN = "\e[1;2B";

    public const string PAGE_DOWN = "\e[6~";

    public const string RIGHT = "\e[C";

    public const string LEFT = "\e[D";

    public const string UP_ARROW = "\eOA";

    public const string DOWN_ARROW = "\eOB";

    public const string RIGHT_ARROW = "\eOC";

    public const string LEFT_ARROW = "\eOD";

    public const string ESCAPE = "\e";

    public const string DELETE = "\e[3~";

    public const string BACKSPACE = "\177";

    public const string ENTER = "\n";

    public const string SPACE = ' ';

    public const string TAB = "\t";

    public const string SHIFT_TAB = "\e[Z";

    public const array HOME = ["\e[1~", "\eOH", "\e[H", "\e[7~"];

    public const array END = ["\e[4~", "\eOF", "\e[F", "\e[8~"];

    /**
     * Cancel/SIGINT.
     */
    public const string CTRL_C = "\x03";

    /**
     * Previous/Up.
     */
    public const string CTRL_P = "\x10";

    /**
     * Next/Down.
     */
    public const string CTRL_N = "\x0E";

    /**
     * Forward/Right.
     */
    public const string CTRL_F = "\x06";

    /**
     * Back/Left.
     */
    public const string CTRL_B = "\x02";

    /**
     * Backspace.
     */
    public const string CTRL_H = "\x08";

    /**
     * Home.
     */
    public const string CTRL_A = "\x01";

    /**
     * EOF.
     */
    public const string CTRL_D = "\x04";

    /**
     * End.
     */
    public const string CTRL_E = "\x05";

    /**
     * Negative affirmation.
     */
    public const string CTRL_U = "\x15";

    public const string OPTION_BACKSPACE = "\e\177";

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
