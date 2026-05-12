<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

class Json
{
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
        return isset(static::$encoder)
            ? (static::$encoder)($value, $flags)
            : json_encode($value, $flags);
    }

    /**
     * Decode the given value.
     */
    public static function decode(mixed $value, ?bool $associative = true): mixed
    {
        return isset(static::$decoder)
            ? (static::$decoder)($value, $associative)
            : json_decode($value, $associative);
    }

    /**
     * Encode all values using the given callable.
     *
     * Boot-only. The encoder persists in a static property for the worker
     * lifetime and affects every subsequent JSON cast encode.
     */
    public static function encodeUsing(?callable $encoder): void
    {
        static::$encoder = $encoder;
    }

    /**
     * Decode all values using the given callable.
     *
     * Boot-only. The decoder persists in a static property for the worker
     * lifetime and affects every subsequent JSON cast decode.
     */
    public static function decodeUsing(?callable $decoder): void
    {
        static::$decoder = $decoder;
    }
}
