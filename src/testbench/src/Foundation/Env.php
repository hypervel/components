<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation;

/**
 * @api
 */
class Env extends \Hypervel\Support\Env
{
    /**
     * Determine if environment variable is available.
     */
    public static function has(string $key): bool
    {
        return static::get($key, new UndefinedValue()) instanceof UndefinedValue === false;
    }

    /**
     * Set an environment value.
     */
    public static function set(string $key, string $value): void
    {
        static::getRepository()->set($key, $value);
    }

    /**
     * Forget an environment variable.
     */
    public static function forget(string $key): bool
    {
        return static::getRepository()->clear($key);
    }

    /**
     * Forward environment value.
     */
    public static function forward(string $key, mixed $default = new UndefinedValue()): mixed
    {
        $value = static::get($key, $default);

        if ($value instanceof UndefinedValue) {
            return false;
        }

        return static::encode($value);
    }

    /**
     * Encode environment variable value.
     */
    public static function encode(mixed $value): mixed
    {
        if ($value === null) {
            return '(null)';
        }

        if (\is_bool($value)) {
            return $value === true ? '(true)' : '(false)';
        }

        if (empty($value)) {
            return '(empty)';
        }

        return $value;
    }
}
