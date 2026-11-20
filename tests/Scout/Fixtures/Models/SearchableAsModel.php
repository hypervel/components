<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Attributes\SearchableAs;
use Hypervel\Scout\Searchable;

#[SearchableAs('posts_index')]
class SearchableAsModel extends Model
{
    use Searchable;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'posts';
}
