<?php

declare(strict_types=1);

namespace Hypervel\Scout\Attributes;

use Attribute;
use Hypervel\Support\Arr;

/**
 * Mark columns for full-text search in the DatabaseEngine.
 *
 * Apply this attribute to the toSearchableArray() method to specify which
 * columns should use full-text search instead of LIKE queries.
 *
 * @example
 * #[SearchUsingFullText(['title', 'body'])]
 * public function toSearchableArray(): array
 *
 * @example With options (Postgres)
 * #[SearchUsingFullText(['title', 'body'], ['mode' => 'websearch', 'language' => 'english'])]
 * public function toSearchableArray(): array
 */
#[Attribute(Attribute::TARGET_METHOD)]
class SearchUsingFullText
{
    /**
     * The full-text columns.
     *
     * @var array<string>
     */
    public readonly array $columns;

    /**
     * The full-text options.
     *
     * @var array<string, mixed>
     */
    public readonly array $options;

    /**
     * Create a new attribute instance.
     *
     * @param array<string>|string $columns
     * @param array{
     *     mode?: 'boolean'|'natural'|'phrase'|'plain'|'raw'|'websearch',
     *     language?: string,
     *     vector?: bool,
     *     trigram?: array<int, string>|string,
     *     trigram_mode?: 'blend'|'fallback',
     *     rank?: 'ts_rank'|'ts_rank_cd',
     *     expanded?: bool,
     *     ...
     * } $options
     */
    public function __construct(array|string $columns, array $options = [])
    {
        $this->columns = Arr::wrap($columns);
        $this->options = $options;
    }
}
