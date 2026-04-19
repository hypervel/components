<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Validation\Enums\CheckType;

/**
 * A single inline validation check within a compiled rule plan.
 *
 * Executed directly by PlanExecutor via exhaustive match over CheckType.
 * Carries all data needed for both execution ($type, $param) and error
 * message generation ($ruleName, $parameters).
 */
final readonly class InlineCheck
{
    /**
     * @param CheckType $type identifies which executor arm runs this check
     * @param mixed $param Literal arguments from the rule (e.g., max threshold, regex pattern, date target).
     * @param string $ruleName Override for the rule name used in addFailure(). When empty,
     *                         getRuleName() falls back to CheckType::ruleName().
     * @param array<int, mixed> $parameters Rule parameters for error message replacement
     *                                      (e.g., ['255'] for max:255).
     */
    public function __construct(
        public CheckType $type,
        public mixed $param = null,
        public string $ruleName = '',
        public array $parameters = [],
    ) {
    }

    /**
     * Get the rule name for addFailure() error message lookup.
     */
    public function getRuleName(): string
    {
        return $this->ruleName !== '' ? $this->ruleName : $this->type->ruleName();
    }
}
