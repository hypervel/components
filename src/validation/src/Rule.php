<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;
use Hyperf\Contract\Arrayable;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Validation\Contracts\InvokableRule;
use Hypervel\Validation\Contracts\ValidationRule;
use Hypervel\Validation\Rules\AnyOf;
use Hypervel\Validation\Rules\ArrayRule;
use Hypervel\Validation\Rules\Can;
use Hypervel\Validation\Rules\Date;
use Hypervel\Validation\Rules\Dimensions;
use Hypervel\Validation\Rules\Email;
use Hypervel\Validation\Rules\Enum;
use Hypervel\Validation\Rules\ExcludeIf;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\File;
use Hypervel\Validation\Rules\ImageFile;
use Hypervel\Validation\Rules\In;
use Hypervel\Validation\Rules\NotIn;
use Hypervel\Validation\Rules\Numeric;
use Hypervel\Validation\Rules\ProhibitedIf;
use Hypervel\Validation\Rules\RequiredIf;
use Hypervel\Validation\Rules\Unique;
use InvalidArgumentException;
use UnitEnum;

class Rule
{
    use Macroable;

    /**
     * Get a can constraint builder instance.
     */
    public static function can(string $ability, mixed ...$arguments): Can
    {
        return new Can($ability, $arguments);
    }

    /**
     * Apply the given rules if the given condition is truthy.
     */
    public static function when(
        bool|callable $condition,
        array|Closure|InvokableRule|Rule|string|ValidationRule $rules,
        array|Closure|InvokableRule|Rule|string|ValidationRule $defaultRules = []
    ): ConditionalRules {
        return new ConditionalRules($condition, $rules, $defaultRules);
    }

    /**
     * Apply the given rules if the given condition is falsy.
     */
    public static function unless(
        bool|callable $condition,
        array|Closure|InvokableRule|Rule|string|ValidationRule $rules,
        array|Closure|InvokableRule|Rule|string|ValidationRule $defaultRules = []
    ) {
        return new ConditionalRules($condition, $defaultRules, $rules);
    }

    /**
     * Get an array rule builder instance.
     */
    public static function array(mixed $keys = null): ArrayRule
    {
        return new ArrayRule(...func_get_args());
    }

    /**
     * Create a new nested rule set.
     */
    public static function forEach(Closure $callback): NestedRules
    {
        return new NestedRules($callback);
    }

    /**
     * Get a unique constraint builder instance.
     */
    public static function unique(string $table, string $column = 'NULL'): Unique
    {
        return new Unique($table, $column);
    }

    /**
     * Get an exists constraint builder instance.
     */
    public static function exists(string $table, string $column = 'NULL'): Exists
    {
        return new Exists($table, $column);
    }

    /**
     * Get an in rule builder instance.
     */
    public static function in(array|Arrayable|UnitEnum|string $values): In
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        return new In(is_array($values) ? $values : func_get_args());
    }

    /**
     * Get a not_in rule builder instance.
     */
    public static function notIn(array|Arrayable|UnitEnum|string $values): NotIn
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        return new NotIn(is_array($values) ? $values : func_get_args());
    }

    /**
     * Get a required_if rule builder instance.
     */
    public static function requiredIf(bool|Closure $callback): RequiredIf
    {
        return new RequiredIf($callback);
    }

    /**
     * Get a exclude_if rule builder instance.
     */
    public static function excludeIf(bool|Closure $callback): ExcludeIf
    {
        return new ExcludeIf($callback);
    }

    /**
     * Get a prohibited_if rule builder instance.
     */
    public static function prohibitedIf(bool|Closure $callback): ProhibitedIf
    {
        return new ProhibitedIf($callback);
    }

    /**
     * Get a date rule builder instance.
     */
    public static function date(): Date
    {
        return new Date();
    }

    /**
     * Get an email rule builder instance.
     */
    public static function email(): Email
    {
        return new Email();
    }

    /**
     * Get an enum rule builder instance.
     *
     * @param class-string $type
     */
    public static function enum(string $type): Enum
    {
        return new Enum($type);
    }

    /**
     * Get a file rule builder instance.
     */
    public static function file(): File
    {
        return new File();
    }

    /**
     * Get an image file rule builder instance.
     */
    public static function imageFile(bool $allowSvg = false): ImageFile
    {
        return new ImageFile($allowSvg);
    }

    /**
     * Get a dimensions rule builder instance.
     */
    public static function dimensions(array $constraints = []): Dimensions
    {
        return new Dimensions($constraints);
    }

    /**
     * Get a numeric rule builder instance.
     */
    public static function numeric(): Numeric
    {
        return new Numeric();
    }

    /**
     * Get an "any of" rule builder instance.
     *
     * @throws InvalidArgumentException
     */
    public static function anyOf(array $rules): AnyOf
    {
        return new AnyOf($rules);
    }

    /**
     * Compile a set of rules for an attribute.
     */
    public static function compile(string $attribute, mixed $rules, ?array $data = null): object
    {
        $parser = new ValidationRuleParser(
            Arr::undot(Arr::wrap($data))
        );

        if (is_array($rules) && ! array_is_list($rules)) {
            $nested = [];

            foreach ($rules as $key => $rule) {
                $nested[$attribute . '.' . $key] = $rule;
            }

            $rules = $nested;
        } else {
            $rules = [$attribute => $rules];
        }

        return $parser->explode(ValidationRuleParser::filterConditionalRules($rules, $data));
    }
}
