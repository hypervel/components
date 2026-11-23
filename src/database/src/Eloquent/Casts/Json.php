<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Support\Attributes\BootOnly;

class Json
{
    private const int MAXIMUM_NESTING_DEPTH = 512;

    /**
     * The custom JSON encoder.
     *
     * @var null|callable
     */
    protected static mixed $encoder = null;

    /**
     * The custom JSON decoder.
     *
     * @var null|callable
     */
    protected static mixed $decoder = null;

    /**
     * Encode the given value.
     */
    public static function encode(mixed $value, int $flags = 0): mixed
    {
        if (isset(static::$encoder)) {
            return (static::$encoder)($value, $flags);
        }

        // Eloquent writers replace false with an error that names the model attribute.
        return json_encode($value, $flags, self::MAXIMUM_NESTING_DEPTH);
    }

    /**
     * Decode the given value.
     */
    public static function decode(mixed $value, ?bool $associative = true): mixed
    {
        if (isset(static::$decoder)) {
            return (static::$decoder)($value, $associative);
        }

        // SQLite returns a JSON number stored in a json or jsonb column as an int or float, which decodes to itself.
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        // An empty string is Eloquent's established empty stored representation.
        return $value === ''
            ? null
            : json_decode($value, $associative, self::MAXIMUM_NESTING_DEPTH + 1, JSON_THROW_ON_ERROR);
    }

    /**
     * Encode all values using the given callable.
     *
     * The encoder persists in a static property for the worker lifetime
     * and affects every subsequent JSON cast encode.
     */
    #[BootOnly]
    public static function encodeUsing(?callable $encoder): void
    {
        static::$encoder = $encoder;
    }

    /**
     * Decode all values using the given callable.
     *
     * The decoder persists in a static property for the worker lifetime
     * and affects every subsequent JSON cast decode.
     */
    #[BootOnly]
    public static function decodeUsing(?callable $decoder): void
    {
        static::$decoder = $decoder;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$encoder = null;
        static::$decoder = null;
    }
}
