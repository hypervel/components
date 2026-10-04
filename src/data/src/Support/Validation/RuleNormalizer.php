<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

use Closure;
use Hypervel\Contracts\Validation\CompilableRules;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Contracts\Validation\ValidationRule as ValidationRuleContract;
use Hypervel\Data\Attributes\Validation\Dimensions;
use Hypervel\Data\Attributes\Validation\Enum;
use Hypervel\Data\Attributes\Validation\Exclude;
use Hypervel\Data\Attributes\Validation\Exists;
use Hypervel\Data\Attributes\Validation\In;
use Hypervel\Data\Attributes\Validation\NotIn;
use Hypervel\Data\Attributes\Validation\Password;
use Hypervel\Data\Attributes\Validation\Prohibited;
use Hypervel\Data\Attributes\Validation\Required;
use Hypervel\Data\Attributes\Validation\Rule;
use Hypervel\Data\Attributes\Validation\Unique;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Exceptions\CouldNotCreateValidationRule;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use Hypervel\Validation\ConditionalRules;
use Hypervel\Validation\Rules\Dimensions as DimensionsRule;
use Hypervel\Validation\Rules\Enum as EnumRule;
use Hypervel\Validation\Rules\ExcludeIf as ExcludeIfRule;
use Hypervel\Validation\Rules\Exists as ExistsRule;
use Hypervel\Validation\Rules\In as InRule;
use Hypervel\Validation\Rules\NotIn as NotInRule;
use Hypervel\Validation\Rules\Password as PasswordRule;
use Hypervel\Validation\Rules\ProhibitedIf as ProhibitedIfRule;
use Hypervel\Validation\Rules\RequiredIf as RequiredIfRule;
use Hypervel\Validation\Rules\Unique as UniqueRule;
use Stringable;
use TypeError;
use ValueError;

class RuleNormalizer
{
    /**
     * Create a rule normalizer.
     */
    public function __construct(protected ValidationRuleFactory $ruleFactory)
    {
    }

    /**
     * Convert a declared rule into validation attributes, so it replaces an inferred rule of the same type.
     *
     * Validation attributes pass through unevaluated, so their references resolve only when the rules compile.
     *
     * @return list<ValidationRule>
     */
    public function execute(
        string|array|Closure|Stringable|CompilableRules|ConditionalRules|ValidationRule|RuleContract|ValidationRuleContract $rule
    ): array {
        if (is_array($rule)) {
            return $this->resolveArrayRule($rule);
        }

        if (is_string($rule)) {
            return $this->resolveStringRule($rule);
        }

        if ($rule instanceof Rule) {
            return $this->execute($rule->get());
        }

        if ($rule instanceof ValidationRule) {
            return [$rule];
        }

        $objectRule = match (true) {
            $rule instanceof DimensionsRule => new Dimensions(rule: $rule),
            $rule instanceof EnumRule => new Enum($rule),
            $rule instanceof ExcludeIfRule => new Exclude($rule),
            $rule instanceof ExistsRule => new Exists(rule: $rule),
            $rule instanceof InRule => new In($rule),
            $rule instanceof NotInRule => new NotIn($rule),
            $rule instanceof PasswordRule => new Password(rule: $rule),
            $rule instanceof ProhibitedIfRule => new Prohibited($rule),
            $rule instanceof RequiredIfRule => new Required($rule),
            $rule instanceof UniqueRule => new Unique(rule: $rule),
            default => null,
        };

        if ($objectRule !== null) {
            return [$objectRule];
        }

        // Closures, custom and fluent rules, and conditional or nested rules stay as given for the validator.
        return [new Rule($rule)];
    }

    /**
     * Normalize every rule in an array.
     *
     * @return list<ValidationRule>
     */
    protected function resolveArrayRule(array $rules): array
    {
        return Arr::flatten(array_map(
            fn (mixed $rule): array => $this->execute($rule),
            $rules
        ));
    }

    /**
     * Normalize a rule string, keeping any rule no attribute can hold as written.
     *
     * @return list<ValidationRule>
     */
    protected function resolveStringRule(string $rule): array
    {
        $rules = [];

        $subRules = Str::contains($rule, 'regex:') ? [$rule] : explode('|', $rule);
        foreach ($subRules as $subRule) {
            try {
                $rules[] = $this->ruleFactory->create($subRule);
            } catch (CouldNotCreateValidationRule|CannotBuildValidationRule|TypeError|ValueError) {
                $rules[] = new Rule($subRule);
            }
        }

        return $rules;
    }
}
