<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Fixtures;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Searchable;

class SearchableTestModel extends Model
{
    use Searchable;

    protected ?string $table = 'searchable_test_models';

    protected array $fillable = ['id', 'name', 'title', 'body'];

    public function searchableAs(): string
    {
        return 'table';
    }

    public function indexableAs(): string
    {
        return 'table';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
