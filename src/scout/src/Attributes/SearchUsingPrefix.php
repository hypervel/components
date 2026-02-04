<?php

declare(strict_types=1);

namespace Hypervel\Scout\Attributes;

use Attribute;
use Hypervel\Support\Arr;

/**
 * Mark columns for prefix search in the DatabaseEngine.
 *
 * Apply this attribute to the toSearchableArray() method to specify which
 * columns should use prefix search (query%) instead of full wildcard (%query%).
 * Prefix search can utilize indexes more effectively.
 *
 * @example
 * #[SearchUsingPrefix(['email', 'username'])]
 * public function toSearchableArray(): array
 */
#[Attribute(Attribute::TARGET_METHOD)]
class SearchUsingPrefix
{
    /**
     * The prefix search columns.
     *
     * @var array<string>
     */
    public readonly array $columns;

    /**
     * Create a new attribute instance.
     *
     * @param array<string>|string $columns
     */
    public function __construct(array|string $columns)
    {
        $this->columns = Arr::wrap($columns);
    }
}
