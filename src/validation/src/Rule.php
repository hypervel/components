<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Validation\Rules\AcceptedIf;
use Hypervel\Validation\Rules\AnyOf;
use Hypervel\Validation\Rules\ArrayKeys;
use Hypervel\Validation\Rules\ArrayRule;
use Hypervel\Validation\Rules\Can;
use Hypervel\Validation\Rules\Confirmed;
use Hypervel\Validation\Rules\Contains;
use Hypervel\Validation\Rules\CurrentPassword;
use Hypervel\Validation\Rules\Date;
use Hypervel\Validation\Rules\DeclinedIf;
use Hypervel\Validation\Rules\Dimensions;
use Hypervel\Validation\Rules\Distinct;
use Hypervel\Validation\Rules\DoesntContain;
use Hypervel\Validation\Rules\Email;
use Hypervel\Validation\Rules\Enum;
use Hypervel\Validation\Rules\ExcludeIf;
use Hypervel\Validation\Rules\ExcludeUnless;
use Hypervel\Validation\Rules\ExcludeWith;
use Hypervel\Validation\Rules\ExcludeWithout;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\File;
use Hypervel\Validation\Rules\ImageFile;
use Hypervel\Validation\Rules\In;
use Hypervel\Validation\Rules\InArray;
use Hypervel\Validation\Rules\InArrayKeys;
use Hypervel\Validation\Rules\IpAddress;
use Hypervel\Validation\Rules\MissingIf;
use Hypervel\Validation\Rules\MissingUnless;
use Hypervel\Validation\Rules\MissingWith;
use Hypervel\Validation\Rules\MissingWithAll;
use Hypervel\Validation\Rules\NotIn;
use Hypervel\Validation\Rules\NotRegex;
use Hypervel\Validation\Rules\Numeric;
use Hypervel\Validation\Rules\PresentIf;
use Hypervel\Validation\Rules\PresentUnless;
use Hypervel\Validation\Rules\PresentWith;
use Hypervel\Validation\Rules\PresentWithAll;
use Hypervel\Validation\Rules\ProhibitedIf;
use Hypervel\Validation\Rules\ProhibitedIfAccepted;
use Hypervel\Validation\Rules\ProhibitedIfDeclined;
use Hypervel\Validation\Rules\ProhibitedUnless;
use Hypervel\Validation\Rules\Prohibits;
use Hypervel\Validation\Rules\Regex;
use Hypervel\Validation\Rules\RequiredArrayKeys;
use Hypervel\Validation\Rules\RequiredIf;
use Hypervel\Validation\Rules\RequiredIfAccepted;
use Hypervel\Validation\Rules\RequiredIfDeclined;
use Hypervel\Validation\Rules\RequiredUnless;
use Hypervel\Validation\Rules\RequiredWith;
use Hypervel\Validation\Rules\RequiredWithAll;
use Hypervel\Validation\Rules\RequiredWithout;
use Hypervel\Validation\Rules\RequiredWithoutAll;
use Hypervel\Validation\Rules\StringRule;
use Hypervel\Validation\Rules\Timezone;
use Hypervel\Validation\Rules\Unique;
use Hypervel\Validation\Rules\Url;
use Hypervel\Validation\Rules\Uuid;
use InvalidArgumentException;
use Stringable;
use UnitEnum;

class Rule
{
    use Macroable;

    /**
     * CoroutineContext key for caching the undotted data in Rule::compile().
     *
     * Set by ValidationRuleParser::explodeWildcardRulesCompilable() to avoid
     * repeated Arr::undot() calls when Rule::forEach expands wildcard items.
     */
    public const string UNDOTTED_DATA_CONTEXT_KEY = '__validation.rule_compile_undotted_data';

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
        array|Closure|RuleContract|string|Stringable|ValidationRule $rules,
        array|Closure|RuleContract|string|Stringable|ValidationRule $defaultRules = []
    ): ConditionalRules {
        return new ConditionalRules($condition, $rules, $defaultRules);
    }

    /**
     * Apply the given rules if the given condition is falsy.
     */
    public static function unless(
        bool|callable $condition,
        array|Closure|RuleContract|string|Stringable|ValidationRule $rules,
        array|Closure|RuleContract|string|Stringable|ValidationRule $defaultRules = []
    ): ConditionalRules {
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
     * Get an array keys rule builder instance.
     */
    public static function arrayKeys(array|Arrayable|UnitEnum|int|string $keys): ArrayKeys
    {
        return new ArrayKeys(...func_get_args());
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
        return new In(...func_get_args());
    }

    /**
     * Get a not_in rule builder instance.
     */
    public static function notIn(array|Arrayable|UnitEnum|string $values): NotIn
    {
        return new NotIn(...func_get_args());
    }

    /**
     * Get an accepted validation rule.
     */
    public static function accepted(): string
    {
        return 'accepted';
    }

    /**
     * Get an active_url validation rule.
     */
    public static function activeUrl(): string
    {
        return 'active_url';
    }

    /**
     * Get a base64 validation rule.
     */
    public static function base64(): string
    {
        return 'base64';
    }

    /**
     * Get a bail validation rule.
     */
    public static function bail(): string
    {
        return 'bail';
    }

    /**
     * Get a boolean validation rule.
     */
    public static function boolean(bool $strict = false): string
    {
        return $strict ? 'boolean:strict' : 'boolean';
    }

    /**
     * Get a declined validation rule.
     */
    public static function declined(): string
    {
        return 'declined';
    }

    /**
     * Get an exclude validation rule.
     */
    public static function exclude(): string
    {
        return 'exclude';
    }

    /**
     * Get a filled validation rule.
     */
    public static function filled(): string
    {
        return 'filled';
    }

    /**
     * Get a hex_color validation rule.
     */
    public static function hexColor(): string
    {
        return 'hex_color';
    }

    /**
     * Get a json validation rule.
     */
    public static function json(): string
    {
        return 'json';
    }

    /**
     * Get a list validation rule.
     */
    public static function list(): string
    {
        return 'list';
    }

    /**
     * Get a mac_address validation rule.
     */
    public static function macAddress(): string
    {
        return 'mac_address';
    }

    /**
     * Get a missing validation rule.
     */
    public static function missing(): string
    {
        return 'missing';
    }

    /**
     * Get a nullable validation rule.
     */
    public static function nullable(): string
    {
        return 'nullable';
    }

    /**
     * Get a present validation rule.
     */
    public static function present(): string
    {
        return 'present';
    }

    /**
     * Get a prohibited validation rule.
     */
    public static function prohibited(): string
    {
        return 'prohibited';
    }

    /**
     * Get a required validation rule.
     */
    public static function required(): string
    {
        return 'required';
    }

    /**
     * Get a sometimes validation rule.
     */
    public static function sometimes(): string
    {
        return 'sometimes';
    }

    /**
     * Get an ulid validation rule.
     */
    public static function ulid(): string
    {
        return 'ulid';
    }

    /**
     * Get an accepted_if rule builder instance.
     */
    public static function acceptedIf(string $anotherField, array|string|int|float|bool|null $value): AcceptedIf
    {
        return new AcceptedIf(...func_get_args());
    }

    /**
     * Get a declined_if rule builder instance.
     */
    public static function declinedIf(string $anotherField, array|string|int|float|bool|null $value): DeclinedIf
    {
        return new DeclinedIf(...func_get_args());
    }

    /**
     * Get a missing_if rule builder instance.
     */
    public static function missingIf(string $anotherField, array|string|int|float|bool|null $value): MissingIf
    {
        return new MissingIf(...func_get_args());
    }

    /**
     * Get a missing_unless rule builder instance.
     */
    public static function missingUnless(string $anotherField, array|string|int|float|bool|null $value): MissingUnless
    {
        return new MissingUnless(...func_get_args());
    }

    /**
     * Get a present_if rule builder instance.
     */
    public static function presentIf(string $anotherField, array|string|int|float|bool|null $value): PresentIf
    {
        return new PresentIf(...func_get_args());
    }

    /**
     * Get a present_unless rule builder instance.
     */
    public static function presentUnless(string $anotherField, array|string|int|float|bool|null $value): PresentUnless
    {
        return new PresentUnless(...func_get_args());
    }

    /**
     * Get a confirmed rule builder instance.
     */
    public static function confirmed(): Confirmed
    {
        return new Confirmed;
    }

    /**
     * Get a current_password rule builder instance.
     */
    public static function currentPassword(): CurrentPassword
    {
        return new CurrentPassword;
    }

    /**
     * Get a distinct rule builder instance.
     */
    public static function distinct(): Distinct
    {
        return new Distinct;
    }

    /**
     * Get an exclude_with rule builder instance.
     */
    public static function excludeWith(string $anotherField): ExcludeWith
    {
        return new ExcludeWith($anotherField);
    }

    /**
     * Get an exclude_without rule builder instance.
     */
    public static function excludeWithout(array|string $anotherField): ExcludeWithout
    {
        return new ExcludeWithout(...func_get_args());
    }

    /**
     * Get an in_array rule builder instance.
     */
    public static function inArray(string $otherField): InArray
    {
        return new InArray($otherField);
    }

    /**
     * Get an in_array_keys rule builder instance.
     */
    public static function inArrayKeys(array|string $keys): InArrayKeys
    {
        return new InArrayKeys(...func_get_args());
    }

    /**
     * Get an IP address rule builder instance.
     */
    public static function ip(): IpAddress
    {
        return new IpAddress;
    }

    /**
     * Get a missing_with rule builder instance.
     */
    public static function missingWith(array|string $fields): MissingWith
    {
        return new MissingWith(...func_get_args());
    }

    /**
     * Get a missing_with_all rule builder instance.
     */
    public static function missingWithAll(array|string $fields): MissingWithAll
    {
        return new MissingWithAll(...func_get_args());
    }

    /**
     * Get a present_with rule builder instance.
     */
    public static function presentWith(array|string $fields): PresentWith
    {
        return new PresentWith(...func_get_args());
    }

    /**
     * Get a present_with_all rule builder instance.
     */
    public static function presentWithAll(array|string $fields): PresentWithAll
    {
        return new PresentWithAll(...func_get_args());
    }

    /**
     * Get a prohibited_if_accepted rule builder instance.
     */
    public static function prohibitedIfAccepted(string $field): ProhibitedIfAccepted
    {
        return new ProhibitedIfAccepted($field);
    }

    /**
     * Get a prohibited_if_declined rule builder instance.
     */
    public static function prohibitedIfDeclined(string $field): ProhibitedIfDeclined
    {
        return new ProhibitedIfDeclined($field);
    }

    /**
     * Get a prohibits rule builder instance.
     */
    public static function prohibits(array|string $fields): Prohibits
    {
        return new Prohibits(...func_get_args());
    }

    /**
     * Get a regex rule builder instance.
     */
    public static function regex(string $pattern): Regex
    {
        return new Regex($pattern);
    }

    /**
     * Get a not_regex rule builder instance.
     */
    public static function notRegex(string $pattern): NotRegex
    {
        return new NotRegex($pattern);
    }

    /**
     * Get a required_array_keys rule builder instance.
     */
    public static function requiredArrayKeys(array|string $keys): RequiredArrayKeys
    {
        return new RequiredArrayKeys(...func_get_args());
    }

    /**
     * Get a required_if_accepted rule builder instance.
     */
    public static function requiredIfAccepted(string $field): RequiredIfAccepted
    {
        return new RequiredIfAccepted($field);
    }

    /**
     * Get a required_if_declined rule builder instance.
     */
    public static function requiredIfDeclined(string $field): RequiredIfDeclined
    {
        return new RequiredIfDeclined($field);
    }

    /**
     * Get a required_with rule builder instance.
     */
    public static function requiredWith(array|string $fields): RequiredWith
    {
        return new RequiredWith(...func_get_args());
    }

    /**
     * Get a required_with_all rule builder instance.
     */
    public static function requiredWithAll(array|string $fields): RequiredWithAll
    {
        return new RequiredWithAll(...func_get_args());
    }

    /**
     * Get a required_without rule builder instance.
     */
    public static function requiredWithout(array|string $fields): RequiredWithout
    {
        return new RequiredWithout(...func_get_args());
    }

    /**
     * Get a required_without_all rule builder instance.
     */
    public static function requiredWithoutAll(array|string $fields): RequiredWithoutAll
    {
        return new RequiredWithoutAll(...func_get_args());
    }

    /**
     * Get a timezone rule builder instance.
     */
    public static function timezone(?array $arguments = null): Timezone
    {
        return new Timezone($arguments);
    }

    /**
     * Get a URL rule builder instance.
     */
    public static function url(): Url
    {
        return new Url;
    }

    /**
     * Get a UUID rule builder instance.
     */
    public static function uuid(): Uuid
    {
        return new Uuid;
    }

    /**
     * Get a required_if rule builder instance.
     *
     * @param null|bool|(Closure(): bool) $callback
     */
    public static function requiredIf(bool|Closure|null $callback): RequiredIf
    {
        return new RequiredIf($callback);
    }

    /**
     * Get a required_unless rule builder instance.
     *
     * @param null|bool|(Closure(): bool) $callback
     */
    public static function requiredUnless(bool|Closure|null $callback): RequiredUnless
    {
        return new RequiredUnless($callback);
    }

    /**
     * Get an exclude_if rule builder instance.
     *
     * @param bool|(Closure(): bool) $callback
     */
    public static function excludeIf(bool|Closure $callback): ExcludeIf
    {
        return new ExcludeIf($callback);
    }

    /**
     * Get an exclude_unless rule builder instance.
     *
     * @param bool|(Closure(): bool) $callback
     */
    public static function excludeUnless(bool|Closure $callback): ExcludeUnless
    {
        return new ExcludeUnless($callback);
    }

    /**
     * Get a prohibited_if rule builder instance.
     *
     * @param bool|(Closure(): bool) $callback
     */
    public static function prohibitedIf(bool|Closure $callback): ProhibitedIf
    {
        return new ProhibitedIf($callback);
    }

    /**
     * Get a prohibited_unless rule builder instance.
     *
     * @param bool|(Closure(): bool) $callback
     */
    public static function prohibitedUnless(bool|Closure $callback): ProhibitedUnless
    {
        return new ProhibitedUnless($callback);
    }

    /**
     * Get a date rule builder instance.
     */
    public static function date(): Date
    {
        return new Date;
    }

    /**
     * Get a datetime rule builder instance.
     */
    public static function dateTime(): Date
    {
        return (new Date)->format('Y-m-d H:i:s');
    }

    /**
     * Get an email rule builder instance.
     */
    public static function email(): Email
    {
        return new Email;
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
        return new File;
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
     * Get a string rule builder instance.
     */
    public static function string(): StringRule
    {
        return new StringRule;
    }

    /**
     * Get a numeric rule builder instance.
     */
    public static function numeric(bool $strict = false): Numeric
    {
        return new Numeric(strict: $strict);
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
     * Get a contains rule builder instance.
     */
    public static function contains(array|Arrayable|UnitEnum|string $values): Contains
    {
        return new Contains(...func_get_args());
    }

    /**
     * Get a doesnt_contain rule builder instance.
     */
    public static function doesntContain(array|Arrayable|UnitEnum|string $values): DoesntContain
    {
        return new DoesntContain(...func_get_args());
    }

    /**
     * Compile a set of rules for an attribute.
     */
    public static function compile(string $attribute, mixed $rules, ?array $data = null): object
    {
        $wrappedData = Arr::wrap($data);

        $cached = CoroutineContext::get(self::UNDOTTED_DATA_CONTEXT_KEY);
        if ($cached !== null && ($cached['input'] === $wrappedData || $cached['dotted'] === $wrappedData)) {
            $undotted = $cached['result'];
        } else {
            $undotted = Arr::undot($wrappedData);
        }

        $parser = new ValidationRuleParser($undotted);

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

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
