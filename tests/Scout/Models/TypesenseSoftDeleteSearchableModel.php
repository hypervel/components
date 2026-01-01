<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

/**
 * Test model for Typesense soft delete integration tests.
 *
 * Includes typesenseCollectionSchema() with __soft_deleted field.
 */
class TypesenseSoftDeleteSearchableModel extends Model implements SearchableInterface
{
    use Searchable;
    use SoftDeletes;

    protected ?string $table = 'soft_deletable_searchable_models';

    protected array $guarded = [];

    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'body' => $this->body ?? '',
        ];
    }

    /**
     * Get the Typesense collection schema.
     *
     * @return array<string, mixed>
     */
    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                ['name' => 'id', 'type' => 'string', 'facet' => true],
                ['name' => 'title', 'type' => 'string', 'facet' => true, 'sort' => true],
                ['name' => 'body', 'type' => 'string', 'facet' => true],
                ['name' => '__soft_deleted', 'type' => 'int32', 'facet' => true],
            ],
        ];
    }

    /**
     * Get the Typesense search parameters.
     *
     * @return array<string, mixed>
     */
    public function typesenseSearchParameters(): array
    {
        return [
            'query_by' => 'title,body',
        ];
    }
}
