<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Completions;

use Hypervel\Support\Str;

class CompletionHelper
{
    /**
     * Filter completion values by a case-insensitive prefix.
     *
     * @param array<string> $items
     * @return array<string>
     */
    public static function filterByPrefix(array $items, string $prefix): array
    {
        if ($prefix === '') {
            return $items;
        }

        $prefixLower = Str::lower($prefix);

        return array_values(array_filter(
            $items,
            fn (string $item): bool => Str::startsWith(Str::lower($item), $prefixLower)
        ));
    }
}
