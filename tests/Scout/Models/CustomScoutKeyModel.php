<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

/**
 * Test model with a custom Scout key.
 */
class CustomScoutKeyModel extends Model implements SearchableInterface
{
    use Searchable;

    protected ?string $table = 'searchable_models';

    protected array $guarded = [];

    /**
     * Custom Scout key using a prefixed format.
     */
    public function getScoutKey(): mixed
    {
        return 'custom-key.' . $this->id;
    }

    public function getScoutKeyName(): string
    {
        return 'id';
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
