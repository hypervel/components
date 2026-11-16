<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;

class EloquentModelUsingIntegerKeyType extends Model
{
    protected string $keyType = 'integer';

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'model';

    /**
     * Get the default foreign key name for the model.
     */
    public function getForeignKey(): string
    {
        return 'model_using_integer_key_type_id';
    }
}
