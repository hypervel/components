<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use Hypervel\Database\Eloquent\Model;
use InvalidArgumentException;

class PermissionConnectionMismatch extends InvalidArgumentException
{
    /**
     * Create a new permission connection mismatch exception.
     */
    public static function forModel(Model $model, string $expectedConnection): static
    {
        return new static(__('Model `:model` uses database connection `:actual`, but Role and Permission models must use permission connection `:expected`.', [
            'model' => $model::class,
            'actual' => $model->getConnection()->getName() ?? '',
            'expected' => $expectedConnection,
        ]));
    }
}
