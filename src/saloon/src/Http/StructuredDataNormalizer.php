<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Stringable;
use InvalidArgumentException;
use JsonSerializable;

final class StructuredDataNormalizer
{
    /**
     * Normalize nested values for JSON encoding.
     */
    public static function forJson(mixed $value): mixed
    {
        $value = self::resolve($value);

        if (is_array($value)) {
            return array_map(self::forJson(...), $value);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException('JSON data must resolve to nested arrays of scalar or null values.');
    }

    /**
     * Normalize nested values for URL encoding.
     */
    public static function forUrlEncoding(mixed $value): mixed
    {
        $value = self::resolve($value);

        if (is_array($value)) {
            return array_map(self::forUrlEncoding(...), $value);
        }

        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            return self::normalizeFloat($value);
        }

        throw new InvalidArgumentException('URL-encoded data must resolve to nested arrays of scalar or null values.');
    }

    /**
     * Resolve a supported structured value.
     */
    private static function resolve(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Stringable => $value->toString(),
            $value instanceof JsonSerializable => self::resolve($value->jsonSerialize()),
            $value instanceof Arrayable => self::resolve($value->toArray()),
            default => $value,
        };
    }

    /**
     * Normalize a float without triggering non-finite conversion warnings.
     */
    private static function normalizeFloat(float $value): float|string
    {
        if (is_finite($value)) {
            return $value;
        }

        return match (true) {
            is_nan($value) => 'NAN',
            $value > 0 => 'INF',
            default => '-INF',
        };
    }
}
