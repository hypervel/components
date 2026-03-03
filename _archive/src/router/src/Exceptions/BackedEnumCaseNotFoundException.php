<?php

declare(strict_types=1);

namespace Hypervel\Router\Exceptions;

use RuntimeException;

class BackedEnumCaseNotFoundException extends RuntimeException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(string $class, string $case)
    {
        parent::__construct("Case [{$case}] not found on Backed Enum [{$class}].");
    }
}
