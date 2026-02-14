<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Models;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

/**
 * Test model that filters models in makeSearchableUsing().
 */
class FilteringSearchableModel extends Model implements SearchableInterface
{
    use Searchable;

    protected ?string $table = 'searchable_models';

    protected array $guarded = [];

    /**
     * Only index models where title doesn't start with "Draft:".
     *
     * @param Collection<int, static> $models
     * @return Collection<int, static>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models->filter(function ($model) {
            return ! str_starts_with($model->title ?? '', 'Draft:');
        })->values();
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
