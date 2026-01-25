<?php

declare(strict_types=1);

namespace Hypervel\Database\Concerns;

use Hypervel\Support\Str;
use Hypervel\Support\Collection;

trait CompilesJsonPaths
{
    /**
     * Split the given JSON selector into the field and the optional path and wrap them separately.
     */
    protected function wrapJsonFieldAndPath(string $column): array
    {
        $parts = explode('->', $column, 2);

        $field = $this->wrap($parts[0]);

        $path = count($parts) > 1 ? ', ' . $this->wrapJsonPath($parts[1], '->') : '';

        return [$field, $path];
    }

    /**
     * Wrap the given JSON path.
     */
    protected function wrapJsonPath(string $value, string $delimiter = '->'): string
    {
        $value = preg_replace("/([\\\\]+)?\\'/", "''", $value);

        $jsonPath = (new Collection(explode($delimiter, $value)))
            ->map(fn ($segment) => $this->wrapJsonPathSegment($segment))
            ->join('.');

        return "'$" . (str_starts_with($jsonPath, '[') ? '' : '.') . $jsonPath . "'";
    }

    /**
     * Wrap the given JSON path segment.
     */
    protected function wrapJsonPathSegment(string $segment): string
    {
        if (preg_match('/(\[[^\]]+\])+$/', $segment, $parts)) {
            $key = Str::beforeLast($segment, $parts[0]);

            if (! empty($key)) {
                return '"' . $key . '"' . $parts[0];
            }

            return $parts[0];
        }

        return '"' . $segment . '"';
    }
}
