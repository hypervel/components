<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Support\Arr;

class ValidationData
{
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
}
