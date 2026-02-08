<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;

class EloquentModelUsingNonIncrementedInt extends Model
{
    protected string $keyType = 'int';

    public bool $incrementing = false;

    protected ?string $table = 'model';

    /**
     * Get the default foreign key name for the model.
     */
    public function getForeignKey(): string
    {
        return 'model_using_non_incremented_int_id';
    }
}
