<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Validation;

use Stringable;

interface ParameterizedRule extends Stringable
{
    /**
     * Get the rule name followed by its parameters.
     */
    public function toArray(): array;
}
