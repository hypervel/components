<?php

declare(strict_types=1);

namespace Hypervel\Routing\Exceptions;

use RuntimeException;

class BackedEnumCaseNotFoundException extends RuntimeException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(string $backedEnumClass, string $case)
    {
        parent::__construct("Case [{$case}] not found on Backed Enum [{$backedEnumClass}].");
    }
}
