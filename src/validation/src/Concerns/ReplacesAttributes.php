<?php

declare(strict_types=1);

namespace Hypervel\Validation\Concerns;

use Hypervel\Support\Arr;

trait ReplacesAttributes
{
    /**
     * Replace all place-holders for the accepted_if rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceAcceptedIf(string $message, string $attribute, string $rule, array $parameters): string
    {
        $parameters[1] = $this->getDisplayableValue($parameters[0], Arr::get($this->data, $parameters[0]));

        $parameters[0] = $this->getDisplayableAttribute($parameters[0]);

        return str_replace([':other', ':value'], $parameters, $message);
    }

    /**
     * Replace all place-holders for the declined_if rule.
     */
    protected function replaceDeclinedIf(string $message, string $attribute, string $rule, array $parameters): string
    {
        $parameters[1] = $this->getDisplayableValue($parameters[0], Arr::get($this->data, $parameters[0]));

        $parameters[0] = $this->getDisplayableAttribute($parameters[0]);

        return str_replace([':other', ':value'], $parameters, $message);
    }

    /**
     * Replace all place-holders for the between rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceBetween(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace([':min', ':max'], $parameters, $message);
    }

    /**
     * Replace all place-holders for the date_format rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceDateFormat(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':format', $parameters[0], $message);
    }

    /**
     * Replace all place-holders for the decimal rule.
     *
     * @param array<int,int> $parameters
     */
    protected function replaceDecimal(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(
            ':decimal',
            isset($parameters[1])
                ? $parameters[0] . '-' . $parameters[1]
                : $parameters[0],
            $message
        );
    }

    /**
     * Replace all place-holders for the different rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceDifferent(string $message, string $attribute, string $rule, array $parameters): string
    {
        return $this->replaceSame($message, $attribute, $rule, $parameters);
    }

    /**
     * Replace all place-holders for the digits rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceDigits(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':digits', $parameters[0], $message);
    }

    /**
     * Replace all place-holders for the digits (between) rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceDigitsBetween(string $message, string $attribute, string $rule, array $parameters): string
    {
        return $this->replaceBetween($message, $attribute, $rule, $parameters);
    }

    /**
     * Replace all place-holders for the extensions rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceExtensions(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':values', implode(', ', $parameters), $message);
    }

    /**
     * Replace all place-holders for the min rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceMin(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':min', $parameters[0], $message);
    }

    /**
     * Replace all place-holders for the min digits rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceMinDigits(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':min', $parameters[0], $message);
    }

    /**
     * Replace all place-holders for the max rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceMax(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':max', $parameters[0], $message);
    }

    /**
     * Replace all place-holders for the max digits rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceMaxDigits(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':max', $parameters[0], $message);
    }

    /**
     * Replace all place-holders for the missing_if rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceMissingIf(string $message, string $attribute, string $rule, array $parameters): string
    {
        $parameters[1] = $this->getDisplayableValue($parameters[0], Arr::get($this->data, $parameters[0]));

        $parameters[0] = $this->getDisplayableAttribute($parameters[0]);

        return str_replace([':other', ':value'], $parameters, $message);
    }

    /**
     * Replace all place-holders for the missing_unless rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceMissingUnless(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace([':other', ':value'], [
            $this->getDisplayableAttribute($parameters[0]),
            $this->getDisplayableValue($parameters[0], $parameters[1]),
        ], $message);
    }

    /**
     * Replace all place-holders for the missing_with rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceMissingWith(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':values', implode(' / ', $this->getAttributeList($parameters)), $message);
    }

    /**
     * Replace all place-holders for the missing_with_all rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceMissingWithAll(string $message, string $attribute, string $rule, array $parameters): string
    {
        return $this->replaceMissingWith($message, $attribute, $rule, $parameters);
    }

    /**
     * Replace all place-holders for the multiple_of rule.
     *
     * @param array<int,string> $parameters
     * @param mixed $message
     */
    protected function replaceMultipleOf($message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':value', $parameters[0] ?? '', $message);
    }

    /**
     * Replace all place-holders for the in rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceIn(string $message, string $attribute, string $rule, array $parameters): string
    {
        foreach ($parameters as &$parameter) {
            $parameter = $this->getDisplayableValue($attribute, $parameter);
        }

        return str_replace(':values', implode(', ', $parameters), $message);
    }

    /**
     * Replace all place-holders for the not_in rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceNotIn(string $message, string $attribute, string $rule, array $parameters): string
    {
        return $this->replaceIn($message, $attribute, $rule, $parameters);
    }

    /**
     * Replace all place-holders for the in_array rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceInArray(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':other', $this->getDisplayableAttribute($parameters[0]), $message);
    }

    /**
     * Replace all place-holders for the required_array_keys rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceRequiredArrayKeys(string $message, string $attribute, string $rule, array $parameters): string
    {
        foreach ($parameters as &$parameter) {
            $parameter = $this->getDisplayableValue($attribute, $parameter);
        }

        return str_replace(':values', implode(', ', $parameters), $message);
    }

    /**
     * Replace all place-holders for the mimetypes rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceMimetypes(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':values', implode(', ', $parameters), $message);
    }

    /**
     * Replace all place-holders for the mimes rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceMimes(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':values', implode(', ', $parameters), $message);
    }

    /**
     * Replace all place-holders for the present_if rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replacePresentIf(string $message, string $attribute, string $rule, array $parameters): string
    {
        $parameters[1] = $this->getDisplayableValue($parameters[0], Arr::get($this->data, $parameters[0]));
        $parameters[0] = $this->getDisplayableAttribute($parameters[0]);

        return str_replace([':other', ':value'], $parameters, $message);
    }

    /**
     * Replace all place-holders for the present_unless rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replacePresentUnless(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace([':other', ':value'], [
            $this->getDisplayableAttribute($parameters[0]),
            $this->getDisplayableValue($parameters[0], $parameters[1]),
        ], $message);
    }

    /**
     * Replace all place-holders for the present_with rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replacePresentWith(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':values', implode(' / ', $this->getAttributeList($parameters)), $message);
    }

    /**
     * Replace all place-holders for the present_with_all rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replacePresentWithAll(string $message, string $attribute, string $rule, array $parameters): string
    {
        return $this->replacePresentWith($message, $attribute, $rule, $parameters);
    }

    /**
     * Replace all place-holders for the required_with rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceRequiredWith(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':values', implode(' / ', $this->getAttributeList($parameters)), $message);
    }

    /**
     * Replace all place-holders for the required_with_all rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceRequiredWithAll(string $message, string $attribute, string $rule, array $parameters): string
    {
        return $this->replaceRequiredWith($message, $attribute, $rule, $parameters);
    }

    /**
     * Replace all place-holders for the required_without rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceRequiredWithout(string $message, string $attribute, string $rule, array $parameters): string
    {
        return $this->replaceRequiredWith($message, $attribute, $rule, $parameters);
    }

    /**
     * Replace all place-holders for the required_without_all rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceRequiredWithoutAll(string $message, string $attribute, string $rule, array $parameters): string
    {
        return $this->replaceRequiredWith($message, $attribute, $rule, $parameters);
    }

    /**
     * Replace all place-holders for the size rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceSize(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':size', $parameters[0], $message);
    }

    /**
     * Replace all place-holders for the gt rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceGt(string $message, string $attribute, string $rule, array $parameters): string
    {
        if (is_null($value = $this->getValue($parameters[0]))) {
            return str_replace(':value', $this->getDisplayableAttribute($parameters[0]), $message);
        }

        return str_replace(':value', (string) $this->getSize($attribute, $value), $message);
    }

    /**
     * Replace all place-holders for the lt rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceLt(string $message, string $attribute, string $rule, array $parameters): string
    {
        if (is_null($value = $this->getValue($parameters[0]))) {
            return str_replace(':value', $this->getDisplayableAttribute($parameters[0]), $message);
        }

        return str_replace(':value', (string) $this->getSize($attribute, $value), $message);
    }

    /**
     * Replace all place-holders for the gte rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceGte(string $message, string $attribute, string $rule, array $parameters): string
    {
        if (is_null($value = $this->getValue($parameters[0]))) {
            return str_replace(':value', $this->getDisplayableAttribute($parameters[0]), $message);
        }

        return str_replace(':value', (string) $this->getSize($attribute, $value), $message);
    }

    /**
     * Replace all place-holders for the lte rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceLte(string $message, string $attribute, string $rule, array $parameters): string
    {
        if (is_null($value = $this->getValue($parameters[0]))) {
            return str_replace(':value', $this->getDisplayableAttribute($parameters[0]), $message);
        }

        return str_replace(':value', (string) $this->getSize($attribute, $value), $message);
    }

    /**
     * Replace all place-holders for the required_if rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceRequiredIf(string $message, string $attribute, string $rule, array $parameters): string
    {
        $parameters[1] = $this->getDisplayableValue($parameters[0], Arr::get($this->data, $parameters[0]));

        $parameters[0] = $this->getDisplayableAttribute($parameters[0]);

        return str_replace([':other', ':value'], $parameters, $message);
    }

    /**
     * Replace all place-holders for the required_if_accepted rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceRequiredIfAccepted(string $message, string $attribute, string $rule, array $parameters): string
    {
        $parameters[0] = $this->getDisplayableAttribute($parameters[0]);

        return str_replace([':other'], $parameters, $message);
    }

    /**
     * Replace all place-holders for the required_if_declined rule.
     *
     * @param array<int,string> $parameters
     */
    public function replaceRequiredIfDeclined(string $message, string $attribute, string $rule, array $parameters): string
    {
        $parameters[0] = $this->getDisplayableAttribute($parameters[0]);

        return str_replace([':other'], $parameters, $message);
    }

    /**
     * Replace all place-holders for the required_unless rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceRequiredUnless(string $message, string $attribute, string $rule, array $parameters): string
    {
        $other = $this->getDisplayableAttribute($parameters[0]);

        $values = [];

        foreach (array_slice($parameters, 1) as $value) {
            $values[] = $this->getDisplayableValue($parameters[0], $value);
        }

        return str_replace([':other', ':values'], [$other, implode(', ', $values)], $message);
    }

    /**
     * Replace all place-holders for the prohibited_if rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceProhibitedIf(string $message, string $attribute, string $rule, array $parameters): string
    {
        $parameters[1] = $this->getDisplayableValue($parameters[0], Arr::get($this->data, $parameters[0]));

        $parameters[0] = $this->getDisplayableAttribute($parameters[0]);

        return str_replace([':other', ':value'], $parameters, $message);
    }

    /**
     * Replace all place-holders for the prohibited_if_accepted rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceProhibitedIfAccepted(string $message, string $attribute, string $rule, array $parameters): string
    {
        $parameters[0] = $this->getDisplayableAttribute($parameters[0]);

        return str_replace([':other'], $parameters, $message);
    }

    /**
     * Replace all place-holders for the prohibited_if_declined rule.
     *
     * @param array<int,string> $parameters
     */
    public function replaceProhibitedIfDeclined(string $message, string $attribute, string $rule, array $parameters): string
    {
        $parameters[0] = $this->getDisplayableAttribute($parameters[0]);

        return str_replace([':other'], $parameters, $message);
    }

    /**
     * Replace all place-holders for the prohibited_unless rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceProhibitedUnless(string $message, string $attribute, string $rule, array $parameters): string
    {
        $other = $this->getDisplayableAttribute($parameters[0]);

        $values = [];

        foreach (array_slice($parameters, 1) as $value) {
            $values[] = $this->getDisplayableValue($parameters[0], $value);
        }

        return str_replace([':other', ':values'], [$other, implode(', ', $values)], $message);
    }

    /**
     * Replace all place-holders for the prohibited_with rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceProhibits(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':other', implode(' / ', $this->getAttributeList($parameters)), $message);
    }

    /**
     * Replace all place-holders for the same rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceSame(string $message, string $attribute, string $rule, array $parameters): string
    {
        return str_replace(':other', $this->getDisplayableAttribute($parameters[0]), $message);
    }

    /**
     * Replace all place-holders for the before rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceBefore(string $message, string $attribute, string $rule, array $parameters): string
    {
        if (! strtotime($parameters[0])) {
            return str_replace(':date', $this->getDisplayableAttribute($parameters[0]), $message);
        }

        return str_replace(':date', $this->getDisplayableValue($attribute, $parameters[0]), $message);
    }

    /**
     * Replace all place-holders for the before_or_equal rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceBeforeOrEqual(string $message, string $attribute, string $rule, array $parameters): string
    {
        return $this->replaceBefore($message, $attribute, $rule, $parameters);
    }

    /**
     * Replace all place-holders for the after rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceAfter(string $message, string $attribute, string $rule, array $parameters): string
    {
        return $this->replaceBefore($message, $attribute, $rule, $parameters);
    }

    /**
     * Replace all place-holders for the after_or_equal rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceAfterOrEqual(string $message, string $attribute, string $rule, array $parameters): string
    {
        return $this->replaceBefore($message, $attribute, $rule, $parameters);
    }

    /**
     * Replace all place-holders for the date_equals rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceDateEquals(string $message, string $attribute, string $rule, array $parameters): string
    {
        return $this->replaceBefore($message, $attribute, $rule, $parameters);
    }

    /**
     * Replace all place-holders for the dimensions rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceDimensions(string $message, string $attribute, string $rule, array $parameters): string
    {
        $parameters = $this->parseNamedParameters($parameters);

        if (is_array($parameters)) {
            foreach ($parameters as $key => $value) {
                $message = str_replace(':' . $key, $value, $message);
            }
        }

        return $message;
    }

    /**
     * Replace all place-holders for the ends_with rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceEndsWith(string $message, string $attribute, string $rule, array $parameters): string
    {
        foreach ($parameters as &$parameter) {
            $parameter = $this->getDisplayableValue($attribute, $parameter);
        }

        return str_replace(':values', implode(', ', $parameters), $message);
    }

    /**
     * Replace all place-holders for the doesnt_end_with rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceDoesntEndWith(string $message, string $attribute, string $rule, array $parameters): string
    {
        foreach ($parameters as &$parameter) {
            $parameter = $this->getDisplayableValue($attribute, $parameter);
        }

        return str_replace(':values', implode(', ', $parameters), $message);
    }

    /**
     * Replace all place-holders for the starts_with rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceStartsWith(string $message, string $attribute, string $rule, array $parameters): string
    {
        foreach ($parameters as &$parameter) {
            $parameter = $this->getDisplayableValue($attribute, $parameter);
        }

        return str_replace(':values', implode(', ', $parameters), $message);
    }

    /**
     * Replace all place-holders for the doesnt_start_with rule.
     *
     * @param array<int,string> $parameters
     */
    protected function replaceDoesntStartWith(string $message, string $attribute, string $rule, array $parameters): string
    {
        foreach ($parameters as &$parameter) {
            $parameter = $this->getDisplayableValue($attribute, $parameter);
        }

        return str_replace(':values', implode(', ', $parameters), $message);
    }
}
