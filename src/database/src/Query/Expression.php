<?php

declare(strict_types=1);

namespace Hypervel\Database\Query;

use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Database\Grammar;

/**
 * @template TValue of string|int|float
 */
class Expression implements ExpressionContract
{
    /**
     * Create a new raw query expression.
     *
     * @param TValue $value
     */
    public function __construct(
        protected string|int|float $value,
    ) {
    }

    /**
     * Get the value of the expression.
     *
     * @return TValue
     */
    public function getValue(Grammar $grammar): string|int|float
    {
        return $this->value;
    }
}
