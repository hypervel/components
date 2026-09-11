<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Searchable;

/**
 * Test model with soft deletes for Scout tests.
 */
class SoftDeletableSearchableModel extends Model
{
    use Searchable;
    use SoftDeletes;

    protected ?string $table = 'soft_deletable_searchable_models';

    protected array $guarded = [];

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
