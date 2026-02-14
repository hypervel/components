<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

/**
 * Test model for Typesense config-based settings.
 *
 * This model does NOT define typesenseCollectionSchema() or typesenseSearchParameters(),
 * so the engine must read settings from config.
 */
class ConfigBasedTypesenseModel extends Model implements SearchableInterface
{
    use Searchable;

    protected ?string $table = 'searchable_models';

    protected array $guarded = [];

    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'body' => $this->body ?? '',
        ];
    }
}
