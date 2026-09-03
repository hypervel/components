<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Concerns;

use Closure;
use Hypervel\Data\Support\Validation\Constraints\DatabaseConstraint;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;
use InvalidArgumentException;

trait AppliesDatabaseConstraints
{
    /**
     * Apply database constraints to a validation rule.
     *
     * @param array<int, Closure|DatabaseConstraint>|Closure|DatabaseConstraint $constraints
     */
    protected function applyDatabaseConstraints(Exists|Unique $rule, Closure|DatabaseConstraint|array $constraints): void
    {
        $constraintsList = is_array($constraints) ? $constraints : [$constraints];

        foreach ($constraintsList as $constraint) {
            match (true) {
                $constraint instanceof Closure => $rule->where($constraint),
                $constraint instanceof DatabaseConstraint => $constraint->apply($rule),
                default => throw new InvalidArgumentException('Each where item must be a DatabaseConstraint or Closure'),
            };
        }
    }
}
