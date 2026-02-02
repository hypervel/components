<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

/**
 * Test model that uses shouldBeSearchable() for conditional indexing.
 */
class ConditionalSearchableModel extends Model implements SearchableInterface
{
    use Searchable;

    protected ?string $table = 'searchable_models';

    protected array $guarded = [];

    /**
     * Only index models where title doesn't contain "hidden".
     */
    public function shouldBeSearchable(): bool
    {
        return ! str_contains($this->title ?? '', 'hidden');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
