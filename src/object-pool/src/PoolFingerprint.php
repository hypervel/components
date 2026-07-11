<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use BackedEnum;
use InvalidArgumentException;
use UnitEnum;

final class PoolFingerprint
{
    /**
     * Fingerprint a pooled object's construction configuration.
     */
    public static function fromConfig(array $config): string
    {
        return 'auto:' . hash('sha256', serialize(self::canonicalize($config, '$')));
    }

    /**
     * Fingerprint an explicitly declared construction equivalence value.
     */
    public static function fromExplicit(string $fingerprint): string
    {
        return 'explicit:' . hash('sha256', $fingerprint);
    }

    /**
     * Canonicalize a value into an unambiguous type-tagged tree.
     */
    private static function canonicalize(mixed $value, string $path): array
    {
        return match (true) {
            $value === null => ['null'],
            is_bool($value) => ['bool', $value],
            is_int($value) => ['int', $value],
            is_float($value) => ['float', $value],
            is_string($value) => ['string', $value],
            $value instanceof BackedEnum => ['enum', $value::class, $value->value],
            $value instanceof UnitEnum => ['enum', $value::class, $value->name],
            is_array($value) && array_is_list($value) => [
                'list',
                array_map(
                    fn (mixed $item, int $index): array => self::canonicalize($item, "{$path}[{$index}]"),
                    $value,
                    array_keys($value),
                ),
            ],
            is_array($value) => self::canonicalizeMap($value, $path),
            default => throw new InvalidArgumentException(
                "Pool fingerprint config value at [{$path}] is of type [" . get_debug_type($value) . '] '
                . 'and cannot define pool identity. Remove it from the construction config or declare '
                . 'construction equivalence explicitly via the pool config\'s "fingerprint" key.'
            ),
        };
    }

    /**
     * Canonicalize an associative array independently of insertion order.
     */
    private static function canonicalizeMap(array $map, string $path): array
    {
        $entries = [];

        foreach ($map as $key => $value) {
            $entries[] = [
                is_int($key) ? ['int', $key] : ['string', $key],
                self::canonicalize($value, "{$path}.{$key}"),
            ];
        }

        // PHP's regular key comparison treats int 1 and string "01" as
        // numerically equal, so it cannot produce a canonical mixed-key order.
        usort($entries, static function (array $left, array $right): int {
            return [$left[0][0], (string) $left[0][1]] <=> [$right[0][0], (string) $right[0][1]];
        });

        return ['map', $entries];
    }
}
