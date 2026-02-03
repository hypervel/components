<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Attributes\SearchUsingPrefix;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

/**
 * Test model that uses prefix search on the title column.
 */
class PrefixSearchableModel extends Model implements SearchableInterface
{
    use Searchable;

    protected ?string $table = 'searchable_models';

    protected array $guarded = [];

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
