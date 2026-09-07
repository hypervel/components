<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation\Constraints;

use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;

class WhereNotNullConstraint extends DatabaseConstraint
{
    /**
     * Create a new where-not-null constraint.
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
        $rule->whereNotNull(
            $this->parseExternalReference($this->column),
        );
    }
}
