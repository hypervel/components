<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;

class EloquentModelUsingUuid extends Model
{
    protected ?string $table = 'model';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    /**
     * Get the default foreign key name for the model.
     */
    public function getForeignKey(): string
    {
        return 'model_using_uuid_id';
    }
}
