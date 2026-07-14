<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use LogicException;

class PermissionPartitionModelNotSupported extends LogicException
{
    /**
     * Create a new unsupported partitioned permission model exception.
     *
     * @param class-string $model
     * @param class-string $requiredBase
     */
    public static function forModel(string $model, string $requiredBase): static
    {
        return new static(__('Partitioned permission model `:model` must extend `:required`.', [
            'model' => $model,
            'required' => $requiredBase,
        ]));
    }
}
