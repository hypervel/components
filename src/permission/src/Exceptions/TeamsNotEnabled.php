<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use BadMethodCallException;

class TeamsNotEnabled extends BadMethodCallException
{
    /**
     * Create a new teams not enabled exception.
     */
    public static function create(): static
    {
        return new static(__('The teams feature is not enabled. Set `teams` to `true` in your permission config file.'));
    }
}
