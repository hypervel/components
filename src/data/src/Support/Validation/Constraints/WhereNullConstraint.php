<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation\Constraints;

use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;

class WhereNullConstraint extends DatabaseConstraint
{
    /**
     * Create a new where-null constraint.
     */
    public function __construct(
        public readonly string|ExternalReference $column,
    ) {
    }

    /**
     * Apply the constraint to a database validation rule.
     */
    public function apply(Exists|Unique $rule): void
    {
        $rule->whereNull(
            $this->parseExternalReference($this->column),
        );
    }
}
