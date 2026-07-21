<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Protocol;

use InvalidArgumentException;

/**
 * @internal
 */
class Timeout
{
    private const MAXIMUM_VALUE = 99_999_999;

    /**
     * Encode seconds as a gRPC timeout header value.
     */
    public static function encode(float $seconds): string
    {
        if (! is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException(
                'The gRPC timeout must be a non-negative finite number of seconds.',
            );
        }

        foreach ([
            'n' => 1_000_000_000,
            'u' => 1_000_000,
            'm' => 1_000,
            'S' => 1,
            'M' => 1 / 60,
            'H' => 1 / 3600,
        ] as $unit => $unitsPerSecond) {
            $value = ceil($seconds * $unitsPerSecond);

            if ($value <= self::MAXIMUM_VALUE) {
                return (int) $value . $unit;
            }
        }

        throw new InvalidArgumentException('The gRPC timeout exceeds the eight-digit wire limit.');
    }

    /**
     * Decode a gRPC timeout header value into seconds.
     */
    public static function decode(string $header): float
    {
        if (! preg_match('/^([0-9]{1,8})([HMSmun])$/D', $header, $matches)) {
            throw new InvalidArgumentException('The gRPC timeout header is malformed.');
        }

        $multiplier = match ($matches[2]) {
            'H' => 3600,
            'M' => 60,
            'S' => 1,
            'm' => 0.001,
            'u' => 0.000001,
            'n' => 0.000000001,
        };

        return (int) $matches[1] * $multiplier;
    }
}
