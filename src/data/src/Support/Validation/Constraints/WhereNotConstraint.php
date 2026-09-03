<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation\Constraints;

use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;

class WhereNotConstraint extends DatabaseConstraint
{
    /**
     * Create a new where-not constraint.
     */
    public function __construct(
        public readonly string|ExternalReference $column,
        public readonly mixed $value,
    ) {
    }

    /**
     * Apply the constraint to a database validation rule.
     */
    public function apply(Exists|Unique $rule): void
    {
        $rule->whereNot(
            $this->parseExternalReference($this->column),
            $this->parseExternalReference($this->value),
        );
    }
}
