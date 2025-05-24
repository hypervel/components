<?php

declare(strict_types=1);

namespace Hypervel\Validation\Contracts;

use stdClass;

interface CompilableRules
{
    /**
     * Compile the object into usable rules.
     */
    public function compile(string $attribute, mixed $value, mixed $data = null, mixed $context = null): stdClass;
}
