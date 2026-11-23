<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Support;

final class Configured
{
    /**
     * Get a configured string value, treating empty or non-string values as missing.
     *
     * Unlike the config repository's string() method, this never throws on an
     * unexpected type, since diagnostics must be able to inspect misconfigured
     * applications without failing before they can report.
     *
     * @return ($default is string ? string : null|string)
     */
    public static function string(string $key, ?string $default = null): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Get the given configuration keys that do not hold a usable value.
     *
     * Null values, blank strings, and empty arrays are treated as missing.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public static function missing(array $keys): array
    {
        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => blank(config($key)),
        ));
    }
}
