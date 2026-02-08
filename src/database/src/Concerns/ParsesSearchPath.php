<?php

declare(strict_types=1);

namespace Hypervel\Database\Concerns;

trait ParsesSearchPath
{
    /**
     * Parse the Postgres "search_path" configuration value into an array.
     */
    protected function parseSearchPath(string|array|null $searchPath): array
    {
        if (is_string($searchPath)) {
            preg_match_all('/[^\s,"\']+/', $searchPath, $matches);

            $searchPath = $matches[0];
        }

        return array_map(function ($schema) {
            return trim($schema, '\'"');
        }, $searchPath ?? []);
    }
}
