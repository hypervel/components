<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Searchable;

/**
 * Test model whose embedding source is a precomputed vector or its name.
 */
class SearchableModelWithPrecomputedEmbedding extends Model
{
    use Searchable;

    protected array $fillable = ['id', 'name'];

    public bool $timestamps = false;

    /**
     * Get the index name for the model when searching.
     */
    public function searchableAs(): string
    {
        return 'table';
    }

    /**
     * Get the index name for the model when indexing.
     */
    public function indexableAs(): string
    {
        return 'table';
    }

    /**
     * Get the source text or precomputed vector to embed.
     *
     * @return array<int, float|int>|string
     */
    public function toSearchableEmbedding(): array|string
    {
        return $this->embedding ?? $this->name;
    }
}
