<?php

declare(strict_types=1);

namespace Hypervel\Validation;

/**
 * A validation check that delegates to an existing validate*() method or Rule object.
 *
 * Used for rules that cannot be inlined: cross-field references, DB rules,
 * implicit rules, custom Rule objects, Exists/Unique objects, etc.
 */
final readonly class DelegatedCheck
{
    /**
     * @param string $ruleName Parsed rule name (e.g., 'Exists', 'Required'). Empty for
     *                         Rule objects dispatched via validateUsingCustomRule().
     * @param array<int, mixed> $parameters parsed rule parameters
     * @param null|object $ruleObject The original rule object (RuleContract, Exists, Unique, etc.).
     *                                Typed as `object` (not RuleContract) because Exists/Unique
     *                                implement Stringable, not RuleContract.
     * @param mixed $originalRule The raw rule as it appears in the exploded rules array.
     *                            Set as $this->currentRule before dispatch so validateExists/
     *                            validateUnique can check `$this->currentRule instanceof Exists`.
     */
    public function __construct(
        public string $ruleName,
        public array $parameters,
        public ?object $ruleObject = null,
        public mixed $originalRule = null,
    ) {
    }

    /**
     * Get the rule name for addFailure() error message lookup.
     */
    public function getRuleName(): string
    {
        return $this->ruleName;
    }
}
