<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Container;

interface ExecutionScopedAttribute extends ContextualAttribute
{
    /**
     * Determine whether the resolved value belongs to the current execution.
     */
    public function isExecutionScoped(): bool;
}
