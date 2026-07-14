<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use LogicException;

class PermissionPartitionAlreadyConfigured extends LogicException
{
    /**
     * Create a new permission partition already configured exception.
     */
    public static function create(): static
    {
        return new static(__('Permission partitioning has already been configured or the permission registrar has already initialized.'));
    }
}
