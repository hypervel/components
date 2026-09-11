<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Searchable;

/**
 * Test model with a custom Scout key.
 */
class CustomScoutKeyModel extends Model
{
    use Searchable;

    protected ?string $table = 'searchable_models';

    protected array $guarded = [];

    /**
     * Get the custom Scout key using a prefixed format.
     */
    public function getScoutKey(): string
    {
        return 'custom-key-' . $this->id;
    }

    /**
     * Get the Scout key name.
     */
    public function getScoutKeyName(): string
    {
        return 'id';
    }

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
