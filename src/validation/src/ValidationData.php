<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Support\Arr;
use Hypervel\Support\Str;

class ValidationData
{
    /**
     * The current placeholder hash.
     */
    protected static ?string $placeholderHash = null;

    /**
     * Encode escaped dots and asterisks in an attribute.
     */
    public static function encodeAttribute(string $attribute): string
    {
        $placeholderHash = static::placeholderHash();

        return str_replace(
            ['\.', '\*'],
            ['__dot__' . $placeholderHash, '__asterisk__' . $placeholderHash],
            $attribute,
        );
    }

    /**
     * Decode escaped dots and asterisks in an attribute.
     */
    public static function decodeAttribute(string $attribute): string
    {
        $placeholderHash = static::placeholderHash();

        return str_replace(
            ['__dot__' . $placeholderHash, '__asterisk__' . $placeholderHash],
            ['\.', '\*'],
            $attribute,
        );
    }

    /**
     * Encode literal dots and asterisks in data keys.
     */
    public static function encodeKeys(array $data): array
    {
        $encodedData = [];
        $placeholderHash = static::placeholderHash();

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = static::encodeKeys($value);
            }

            $key = str_replace(
                ['.', '*'],
                ['__dot__' . $placeholderHash, '__asterisk__' . $placeholderHash],
                (string) $key,
            );

            $encodedData[$key] = $value;
        }

        return $encodedData;
    }

    /**
     * Decode literal dots and asterisks in data keys.
     */
    public static function decodeKeys(array $data): array
    {
        $decodedData = [];

        foreach ($data as $key => $value) {
            $decodedData[static::replacePlaceholderInString((string) $key)] = is_array($value)
                ? static::decodeKeys($value)
                : $value;
        }

        return $decodedData;
    }

    /**
     * Replace placeholders in the given string.
     */
    public static function replacePlaceholderInString(string $value): string
    {
        $placeholderHash = static::placeholderHash();

        return str_replace(
            ['__dot__' . $placeholderHash, '__asterisk__' . $placeholderHash],
            ['.', '*'],
            $value,
        );
    }

    /**
     * Expand a wildcard attribute into its matching concrete keys.
     *
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    public static function expandWildcardKeys(string $attribute, array $data): array
    {
        $segments = explode('.', $attribute);
        $results = [];

        self::traverseWildcardSegments($segments, 0, $data, '', $results);

        return $results;
    }

    /**
     * Initialize and gather data for the given attribute.
     */
    public static function initializeAndGatherData(string $attribute, array $masterData): array
    {
        $data = Arr::dot(static::initializeAttributeOnData($attribute, $masterData));

        return array_merge($data, static::extractValuesForWildcards(
            $masterData,
            $data,
            $attribute
        ));
    }

    /**
     * Gather a copy of the attribute data filled with any missing attributes.
     */
    protected static function initializeAttributeOnData(string $attribute, array $masterData): array
    {
        $explicitPath = static::getLeadingExplicitAttributePath($attribute);

        $data = static::extractDataFromPath($explicitPath, $masterData);

        if (! str_contains($attribute, '*') || str_ends_with($attribute, '*')) {
            return $data;
        }

        return data_set($data, $attribute, null, true);
    }

    /**
     * Get all of the exact attribute values for a given wildcard attribute.
     */
    protected static function extractValuesForWildcards(array $masterData, array $data, string $attribute): array
    {
        $keys = [];

        $pattern = str_replace('\*', '[^\.]+', preg_quote($attribute, '/'));

        foreach ($data as $key => $value) {
            if ((bool) preg_match('/^' . $pattern . '/', (string) $key, $matches)) {
                $keys[] = $matches[0];
            }
        }

        $keys = array_unique($keys);

        $data = [];

        foreach ($keys as $key) {
            $data[$key] = Arr::get($masterData, $key);
        }

        return $data;
    }

    /**
     * Extract data based on the given dot-notated path.
     *
     * Used to extract a sub-section of the data for faster iteration.
     */
    public static function extractDataFromPath(?string $attribute, array $masterData): array
    {
        $results = [];

        $value = Arr::get($masterData, $attribute, '__missing__');

        if ($value !== '__missing__') {
            Arr::set($results, $attribute, $value);
        }

        return $results;
    }

    /**
     * Get the explicit part of the attribute name.
     *
     * E.g. 'foo.bar.*.baz' -> 'foo.bar'
     *
     * Allows us to not spin through all of the flattened data for some operations.
     */
    public static function getLeadingExplicitAttributePath(string $attribute): ?string
    {
        return rtrim(explode('*', $attribute)[0], '.') ?: null;
    }

    /**
     * Get the placeholder hash.
     */
    protected static function placeholderHash(): string
    {
        return static::$placeholderHash ??= Str::random();
    }

    /**
     * Recursively traverse data segments to expand wildcard keys.
     *
     * @param list<string> $segments
     * @param list<string> $results
     */
    private static function traverseWildcardSegments(
        array $segments,
        int $index,
        mixed $data,
        string $prefix,
        array &$results,
    ): void {
        if ($index >= count($segments)) {
            $results[] = rtrim($prefix, '.');

            return;
        }

        $segment = $segments[$index];

        if ($segment === '*') {
            if (! is_array($data)) {
                return;
            }

            foreach ($data as $key => $value) {
                self::traverseWildcardSegments($segments, $index + 1, $value, $prefix . $key . '.', $results);
            }

            return;
        }

        if (str_contains($segment, '*')) {
            if (! is_array($data)) {
                return;
            }

            $pattern = '/^' . str_replace('\*', '[^\.]*', preg_quote($segment, '/')) . '\z/';

            foreach ($data as $key => $value) {
                if (preg_match($pattern, (string) $key) === 1) {
                    self::traverseWildcardSegments($segments, $index + 1, $value, $prefix . $key . '.', $results);
                }
            }

            return;
        }

        $nextData = is_array($data) && array_key_exists($segment, $data) ? $data[$segment] : null;

        self::traverseWildcardSegments($segments, $index + 1, $nextData, $prefix . $segment . '.', $results);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$placeholderHash = null;
    }
}
