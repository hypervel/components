<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use RuntimeException;

class PermissionPartitionNotResolved extends RuntimeException
{
    /**
     * Create a new unresolved permission partition exception.
     */
    public static function forColumn(string $column): static
    {
        return new static(__('Permission partition `:column` could not be resolved. No unpartitioned fallback is allowed.', [
            'column' => $column,
        ]));
    }
}
