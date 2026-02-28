<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Fixtures\Models;

use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Model;

class EloquentModelUsingUlid extends Model
{
    use HasUlids;

    protected ?string $table = 'model';

    /**
     * Get the default foreign key name for the model.
     */
    public function getForeignKey(): string
    {
        return 'model_using_ulid_id';
    }
}
