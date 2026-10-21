<?php

declare(strict_types=1);

namespace Hypervel\Data\RuleInferrers;

use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Validation\PropertyRules;
use Hypervel\Data\Support\Validation\ValidationContext;

// REMOVED: The built-in rule inferrers; Hypervel's fixed inference runs before configured inferrers.
interface RuleInferrer
{
    /**
     * Adjust the rules inferred for one property.
     */
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules;
}
