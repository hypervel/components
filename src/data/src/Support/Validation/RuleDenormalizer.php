<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

use BackedEnum;
use DateTimeInterface;
use Hypervel\Contracts\Validation\InvokableRule as InvokableRuleContract;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Data\Attributes\Validation\CustomValidationAttribute;
use Hypervel\Data\Attributes\Validation\ObjectValidationAttribute;
use Hypervel\Data\Attributes\Validation\Rule;
use Hypervel\Data\Attributes\Validation\StringValidationAttribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\References\FieldReference;

class RuleDenormalizer
{
    /**
     * Convert one declaration into Validator rules.
     *
     * @return list<InvokableRuleContract|object|RuleContract|string>
     */
    public function execute(mixed $rule, ValidationPath $path): array
    {
        if (is_string($rule)) {
            return str_contains($rule, 'regex:') ? [$rule] : explode('|', $rule);
        }

        if (is_array($rule)) {
            $rules = [];

            foreach ($rule as $nestedRule) {
                array_push($rules, ...$this->execute($nestedRule, $path));
            }

            return $rules;
        }

        if ($rule instanceof StringValidationAttribute) {
            return $this->normalizeStringValidationAttribute($rule, $path);
        }

        if ($rule instanceof ObjectValidationAttribute) {
            return [$rule->getRule($path)];
        }

        if ($rule instanceof CustomValidationAttribute) {
            $rules = $rule->getRules($path);

            return is_array($rules) ? $rules : [$rules];
        }

        if ($rule instanceof Rule) {
            return $this->execute($rule->get(), $path);
        }

        if ($rule instanceof RuleContract || $rule instanceof InvokableRuleContract) {
            return [$rule];
        }

        return [$rule];
    }

    /**
     * Convert a string attribute into one Validator rule.
     *
     * @return list<string>
     */
    protected function normalizeStringValidationAttribute(
        StringValidationAttribute $rule,
        ValidationPath $path,
    ): array {
        $parameters = [];

        foreach ($rule->parameters() as $key => $value) {
            $parameter = $this->normalizeRuleParameter($value, $path);

            if ($parameter === null) {
                continue;
            }

            $parameters[] = is_string($key) ? "{$key}={$parameter}" : $parameter;
        }

        if ($parameters === []) {
            return [$rule->keyword()];
        }

        return ["{$rule->keyword()}:" . implode(',', $parameters)];
    }

    /**
     * Convert one rule parameter into Validator string form.
     */
    protected function normalizeRuleParameter(
        mixed $parameter,
        ValidationPath $path,
    ): ?string {
        if ($parameter === null) {
            return null;
        }

        if (is_string($parameter) || is_numeric($parameter)) {
            return (string) $parameter;
        }

        if (is_bool($parameter)) {
            return $parameter ? 'true' : 'false';
        }

        if (is_array($parameter) && count($parameter) === 0) {
            return null;
        }

        if (is_array($parameter)) {
            // ValidatesAttributes::convertValuesToNull() decodes list values from this literal token.
            $subParameters = array_map(
                fn (mixed $subParameter): string => $this->normalizeRuleParameter($subParameter, $path) ?? 'null',
                $parameter
            );

            return implode(',', $subParameters);
        }

        if ($parameter instanceof DateTimeInterface) {
            return $parameter->format(DATE_ATOM);
        }

        if ($parameter instanceof BackedEnum) {
            return (string) $parameter->value;
        }

        if ($parameter instanceof FieldReference) {
            return $parameter->getValue($path);
        }

        if ($parameter instanceof ExternalReference) {
            return $this->normalizeRuleParameter($parameter->getValue(), $path);
        }

        return (string) $parameter;
    }
}
