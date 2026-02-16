<?php

declare(strict_types=1);

namespace Hypervel\Support;

use InvalidArgumentException;

class Base62
{
    public const CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public const BASE = 62;

    /**
     * Encode the given number to a base62 string.
     */
    public static function encode(int $number): string
    {
        $chars = [];
        while ($number > 0) {
            $remain = $number % static::BASE;
            $chars[] = static::CHARS[$remain];
            $number = ($number - $remain) / static::BASE;
        }
        return implode('', array_reverse($chars));
    }

    /**
     * Decode the given base62 string to a number.
     *
     * @throws InvalidArgumentException
     */
    public static function decode(string $data): int
    {
        if ($data === '' || strlen($data) !== strspn($data, self::CHARS)) {
            throw new InvalidArgumentException('The decode data contains content outside of CHARS.');
        }

        return array_reduce(
            array_map(fn (string $character): int|false => strpos(static::CHARS, $character), str_split($data)),
            fn (int $result, int $remain): int => $result * static::BASE + $remain,
            0,
        );
    }
}
