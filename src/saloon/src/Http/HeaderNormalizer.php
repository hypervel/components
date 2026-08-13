<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Hypervel\Saloon\Exceptions\InvalidHeaderException;
use Hypervel\Support\Stringable;

final class HeaderNormalizer
{
    /**
     * Normalize request header values.
     *
     * @param array<string, mixed> $headers
     * @return array<string, list<string>|string>
     */
    public static function normalize(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidHeaderException('HTTP header names must be strings.');
            }

            $headers[$name] = self::value($value);
        }

        return $headers;
    }

    /**
     * Normalize one request header value.
     *
     * @return list<string>|string
     */
    private static function value(mixed $value): array|string
    {
        if (! is_array($value)) {
            return self::item($value);
        }

        if ($value === []) {
            return '';
        }

        return array_map(self::item(...), array_values($value));
    }

    /**
     * Normalize one scalar request header item.
     */
    private static function item(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_float($value) && ! is_finite($value) => match (true) {
                is_nan($value) => 'NAN',
                $value > 0 => 'INF',
                default => '-INF',
            },
            is_scalar($value) => (string) $value,
            $value instanceof Stringable => $value->toString(),
            default => throw new InvalidHeaderException('HTTP header values must be scalar, null, Hypervel Stringable, or arrays of those values.'),
        };
    }
}
