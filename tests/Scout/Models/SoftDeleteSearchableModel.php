<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

/**
 * Test model for Scout soft delete integration tests.
 */
class SoftDeleteSearchableModel extends Model implements SearchableInterface
{
    use Searchable;
    use SoftDeletes;

    protected ?string $table = 'soft_deletable_searchable_models';

    protected array $guarded = [];

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body ?? '',
        ];
    }
}
