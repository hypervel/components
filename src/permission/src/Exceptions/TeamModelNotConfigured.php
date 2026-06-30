<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use RuntimeException;

class TeamModelNotConfigured extends RuntimeException
{
    /**
     * Create a new team model not configured exception.
     */
    public static function create(): static
    {
        return new static(__('No team model configured. Set `models.team` in your permission config file.'));
    }
}
