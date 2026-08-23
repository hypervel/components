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
    public bool $parametersAreScalar;

    /**
     * @param string $ruleName Parsed rule name (e.g., 'Exists', 'Required'). Empty for
     *                         Rule objects dispatched via validateUsingCustomRule().
     * @param array<int, mixed> $parameters parsed rule parameters
     * @param mixed $originalRule The raw rule as it appears in the exploded rules array.
     *                            Set as $this->currentRule before dispatch so validateExists/
     *                            validateUnique can check `$this->currentRule instanceof Exists`.
     */
    public function __construct(
        public string $ruleName,
        public array $parameters,
        public mixed $originalRule = null,
    ) {
        $this->parametersAreScalar = array_all(
            $parameters,
            static fn (mixed $parameter): bool => is_scalar($parameter),
        );
    }

    /**
     * Get the rule name for addFailure() error message lookup.
     */
    public function getRuleName(): string
    {
        return $this->ruleName;
    }
}
