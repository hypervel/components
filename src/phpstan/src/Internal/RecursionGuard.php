<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Internal;

/**
 * Prevents infinite recursion when resolving methods/properties through
 * class-reflection extensions. Modeled after PHPStan's RecursionGuard.
 *
 * @internal
 *
 * @see https://github.com/phpstan/phpstan-src/blob/2.2.x/src/Type/RecursionGuard.php
 */
final class RecursionGuard
{
    /** @var array<string, true> */
    private static array $context = [];

    /**
     * Run the callback, or return null when the key is already running.
     *
     * @param callable(): T $callback
     *
     * @return null|T
     *
     * @template T
     */
    public static function run(string $key, callable $callback): mixed
    {
        if (isset(self::$context[$key])) {
            return null;
        }

        try {
            self::$context[$key] = true;

            return $callback();
        } finally {
            unset(self::$context[$key]);
        }
    }
}
