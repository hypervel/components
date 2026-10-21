<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Attributes\SearchUsingPrefix;
use Hypervel\Scout\Searchable;

/**
 * Test model that uses prefix search on the title column.
 */
class PrefixSearchableModel extends Model
{
    use Searchable;

    protected ?string $table = 'searchable_models';

    protected array $guarded = [];

    /**
     * Get the indexable data array for the model.
     */
    #[SearchUsingPrefix(['title'])]
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
