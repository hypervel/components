<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use RuntimeException;

class TeamNotSelected extends RuntimeException
{
    /**
     * Create a new team not selected exception.
     */
    public static function create(): static
    {
        return new static(__('A current team must be selected before changing team-scoped roles or permissions.'));
    }
}
