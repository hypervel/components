<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Fixtures\Models;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Searchable;

/**
 * UUID7-keyed test fixture for the string-key path in scout:queue-import.
 */
class UuidSearchableModel extends Model
{
    use HasUuids;
    use Searchable;

    protected ?string $table = 'uuid_searchable_models';

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
