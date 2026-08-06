<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Support\Arr;

class RouteGroup
{
    /**
     * Merge route groups into a new array.
     */
    public static function merge(array $new, array $old, bool $prependExistingPrefix = true): array
    {
        if (isset($new['domain'])) {
            unset($old['domain']);
        }

        if (isset($new['port'])) {
            unset($old['port']);
        }

        if (isset($new['controller'])) {
            unset($old['controller']);
        }

        $metadata = static::formatMetadata($new, $old);

        unset($new['metadata']);

        $new = array_merge(static::formatAs($new, $old), [
            'namespace' => static::formatNamespace($new, $old),
            'prefix' => static::formatPrefix($new, $old, $prependExistingPrefix),
            'where' => static::formatWhere($new, $old),
        ]);

        if ($metadata !== []) {
            $new['metadata'] = $metadata;
        }

        return array_merge_recursive(Arr::except(
            $old,
            ['metadata', 'namespace', 'prefix', 'where', 'as']
        ), $new);
    }

    /**
     * Format the metadata for the new group attributes.
     */
    protected static function formatMetadata(array $new, array $old): array
    {
        return static::mergeMetadata(
            $old['metadata'] ?? [],
            $new['metadata'] ?? []
        );
    }

    /**
     * Merge the given route metadata.
     *
     * Associative array values are merged recursively, while all other values, including lists, replace the existing value entirely.
     */
    public static function mergeMetadata(array $old, array $new): array
    {
        foreach ($new as $key => $value) {
            if (isset($old[$key]) && static::mergableMetadata($old[$key], $value)) {
                $value = static::mergeMetadata($old[$key], $value);
            }

            $old[$key] = $value;
        }

        return $old;
    }

    /**
     * Determine if the given metadata values should be merged.
     */
    protected static function mergableMetadata(mixed $old, mixed $new): bool
    {
        return is_array($old)
            && is_array($new)
            && Arr::isAssoc($old)
            && Arr::isAssoc($new);
    }

    /**
     * Format the namespace for the new group attributes.
     */
    protected static function formatNamespace(array $new, array $old): ?string
    {
        if (isset($new['namespace'])) {
            return isset($old['namespace']) && ! str_starts_with($new['namespace'], '\\')
                ? trim($old['namespace'], '\\') . '\\' . trim($new['namespace'], '\\')
                : trim($new['namespace'], '\\');
        }

        return $old['namespace'] ?? null;
    }

    /**
     * Format the prefix for the new group attributes.
     */
    protected static function formatPrefix(array $new, array $old, bool $prependExistingPrefix = true): ?string
    {
        $old = $old['prefix'] ?? '';

        if ($prependExistingPrefix) {
            return isset($new['prefix']) ? trim($old, '/') . '/' . trim($new['prefix'], '/') : $old;
        }

        return isset($new['prefix']) ? trim($new['prefix'], '/') . '/' . trim($old, '/') : $old;
    }

    /**
     * Format the "wheres" for the new group attributes.
     */
    protected static function formatWhere(array $new, array $old): array
    {
        return array_merge(
            $old['where'] ?? [],
            $new['where'] ?? []
        );
    }

    /**
     * Format the "as" clause of the new group attributes.
     */
    protected static function formatAs(array $new, array $old): array
    {
        if (isset($old['as'])) {
            $new['as'] = $old['as'] . ($new['as'] ?? '');
        }

        return $new;
    }
}
