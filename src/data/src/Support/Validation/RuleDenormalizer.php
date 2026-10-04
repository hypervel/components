<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Hypervel\Data\Attributes\Validation\CustomValidationAttribute;
use Hypervel\Data\Attributes\Validation\ObjectValidationAttribute;
use Hypervel\Data\Attributes\Validation\Rule;
use Hypervel\Data\Attributes\Validation\StringValidationAttribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\References\FieldReference;
use Hypervel\Support\Arr;

class RuleDenormalizer
{
    /**
     * Convert one declaration into Validator rules.
     *
     * @param null|Closure(FieldReference, ValidationPath): string $resolveField
     * @return list<object|string>
     */
    public function execute(mixed $rule, ValidationPath $path, ?Closure $resolveField = null): array
    {
        if (is_string($rule)) {
            // A regex may contain |, so a string starting with one is a single rule; other strings split like Laravel's.
            return str_starts_with($rule, 'regex:') || str_starts_with($rule, 'not_regex:')
                ? [$rule]
                : explode('|', $rule);
        }

        if (is_array($rule)) {
            $rules = [];

            foreach ($rule as $nestedRule) {
                array_push($rules, ...$this->execute($nestedRule, $path, $resolveField));
            }

            return $rules;
        }

        if ($rule instanceof StringValidationAttribute) {
            return $this->normalizeStringValidationAttribute($rule, $path, $resolveField);
        }

        if ($rule instanceof ObjectValidationAttribute) {
            return [$rule->getRule($path)];
        }

        if ($rule instanceof CustomValidationAttribute) {
            $rules = $rule->getRules($path);

            return is_array($rules) ? $rules : [$rules];
        }

        if ($rule instanceof Rule) {
            return $this->execute($rule->get(), $path, $resolveField);
        }

        return [$rule];
    }

    /**
     * Convert a string attribute into one Validator rule.
     *
     * @param null|Closure(FieldReference, ValidationPath): string $resolveField
     * @return list<string>
     */
    protected function normalizeStringValidationAttribute(
        StringValidationAttribute $rule,
        ValidationPath $path,
        ?Closure $resolveField,
    ): array {
        $parameters = [];
        $quoteParameters = ! in_array($rule->keyword(), ['regex', 'not_regex'], true);

        foreach ($rule->parameters() as $key => $value) {
            $parameter = $this->normalizeRuleParameter($value, $path, $resolveField);

            if ($parameter === null) {
                continue;
            }

            foreach (Arr::wrap($parameter) as $index => $field) {
                if (is_string($key) && $index === 0) {
                    $field = "{$key}={$field}";
                }

                // Quote after adding the name so the entire parameter remains one CSV field.
                $parameters[] = $quoteParameters && strpbrk($field, ',"') !== false
                    ? '"' . str_replace('"', '""', $field) . '"'
                    : $field;
            }
        }

        if ($parameters === []) {
            return [$rule->keyword()];
        }

        return ["{$rule->keyword()}:" . implode(',', $parameters)];
    }

    /**
     * Normalize one rule parameter while preserving its field boundaries.
     *
     * @param null|Closure(FieldReference, ValidationPath): string $resolveField
     * @return null|list<string>|string
     */
    protected function normalizeRuleParameter(
        mixed $parameter,
        ValidationPath $path,
        ?Closure $resolveField,
    ): array|string|null {
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
                fn (mixed $subParameter): array|string => $this->normalizeRuleParameter($subParameter, $path, $resolveField) ?? 'null',
                $parameter
            );

            return Arr::flatten($subParameters);
        }

        if ($parameter instanceof DateTimeInterface) {
            return $parameter->format(DATE_ATOM);
        }

        if ($parameter instanceof BackedEnum) {
            return (string) $parameter->value;
        }

        if ($parameter instanceof FieldReference) {
            return $resolveField === null ? $parameter->getValue($path) : $resolveField($parameter, $path);
        }

        if ($parameter instanceof ExternalReference) {
            return $this->normalizeRuleParameter($parameter->getValue(), $path, $resolveField);
        }

        return (string) $parameter;
    }
}
