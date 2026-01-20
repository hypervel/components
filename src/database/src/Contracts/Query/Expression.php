<?php

declare(strict_types=1);

namespace Hypervel\Database\Contracts\Query;

use Hypervel\Database\Grammar;

interface Expression
{
    /**
     * Get the value of the expression.
     */
    public function getValue(Grammar $grammar): string|int|float;
}
