<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Models;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

/**
 * UUID7-keyed test fixture for the string-key path in scout:queue-import.
 */
class UuidSearchableModel extends Model implements SearchableInterface
{
    use HasUuids;
    use Searchable;

    protected ?string $table = 'uuid_searchable_models';

    protected array $guarded = [];

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
