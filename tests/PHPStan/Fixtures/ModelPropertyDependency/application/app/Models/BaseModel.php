<?php

declare(strict_types=1);

namespace ModelPropertyDependency\Models;

use Hypervel\Database\Eloquent\Model;
use UnitEnum;

abstract class BaseModel extends Model
{
    protected UnitEnum|string|null $connection = null;

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? config('models.connection');
    }
}
