<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;
use Hypervel\Contracts\Validation\CompilableRules;
use Hypervel\Contracts\Validation\InvokableRule;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\StrCache;
use Hypervel\Validation\Rules\Date;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Numeric;
use Hypervel\Validation\Rules\Unique;
use stdClass;
use Stringable;

class ValidationRuleParser
{
    /**
     * The implicit attributes.
     */
    public array $implicitAttributes = [];

    /**
     * Create a new validation rule parser.
     *
     * @param array $data the data being validated
     */
    public function __construct(
        public array $data
    ) {
    }

    /**
     * Parse the human-friendly rules into a full rules array for the validator.
     */
    public function explode(array $rules): stdClass
    {
        $this->implicitAttributes = [];

        $rules = $this->explodeRules($rules);

        return (object) [
            'rules' => $rules,
            'implicitAttributes' => $this->implicitAttributes,
        ];
    }

    /**
     * Explode the rules into an array of explicit rules.
     */
    protected function explodeRules(array $rules): array
    {
        foreach ($rules as $key => $rule) {
            $key = (string) $key;
            if (str_contains($key, '*')) {
                $rules = $this->explodeWildcardRules($rules, $key, [$rule]);

                unset($rules[$key]);
            } else {
                $rules[$key] = $this->explodeExplicitRule($rule, $key);
            }
        }

        return $rules;
    }

    /**
     * Explode the explicit rule into an array if necessary.
     */
    protected function explodeExplicitRule(mixed $rule, string $attribute): array
    {
        if (is_string($rule)) {
            return explode('|', $rule);
        }

        if (is_object($rule)) {
            if ($rule instanceof Date || $rule instanceof Numeric) {
                return explode('|', (string) $rule);
            }

            return Arr::wrap($this->prepareRule($rule, $attribute));
        }

        $rules = [];

        foreach ($rule as $value) {
            if ($value instanceof Date || $value instanceof Numeric) {
                $rules = array_merge($rules, explode('|', (string) $value));
            } else {
                $rules[] = $this->prepareRule($value, $attribute);
            }
        }

        return $rules;
    }

    /**
     * Prepare the given rule for the Validator.
     */
    protected function prepareRule(mixed $rule, string $attribute): mixed
    {
        if ($rule instanceof Closure) {
            $rule = new ClosureValidationRule($rule);
        }

        if ($rule instanceof InvokableRule || $rule instanceof ValidationRule) {
            $rule = InvokableValidationRule::make($rule);
        }

        if (! is_object($rule)
            || $rule instanceof RuleContract
            || ($rule instanceof Exists && $rule->queryCallbacks())
            || ($rule instanceof Unique && $rule->queryCallbacks())
        ) {
            return $rule;
        }

        if ($rule instanceof CompilableRules) {
            return $rule->compile(
                $attribute,
                $this->data[$attribute] ?? null,
                Arr::dot($this->data),
                $this->data
            )->rules[$attribute];
        }

        return $rule;
    }

    /**
     * Define a set of rules that apply to each element in an array attribute.
     */
    protected function explodeWildcardRules(array $results, string $attribute, array|object|string $rules): array
    {
        $pattern = str_replace('\*', '[^\.]*', preg_quote($attribute, '/'));

        $data = ValidationData::initializeAndGatherData($attribute, $this->data);

        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (Str::startsWith($key, $attribute) || (bool) preg_match('/^' . $pattern . '\z/', $key)) {
                foreach ((array) $rules as $rule) {
                    if ($rule instanceof CompilableRules) {
                        $context = Arr::get($this->data, Str::beforeLast($key, '.'));

                        $compiled = $rule->compile($key, $value, $data, $context);

                        $this->implicitAttributes = array_merge_recursive(
                            $compiled->implicitAttributes,
                            $this->implicitAttributes,
                            [$attribute => [$key]]
                        );

                        $results = $this->mergeRules($results, $compiled->rules);
                    } else {
                        $this->implicitAttributes[$attribute][] = $key;

                        $results = $this->mergeRules($results, $key, $rule);
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Merge additional rules into a given attribute(s).
     */
    public function mergeRules(array $results, array|object|string $attribute, array|object|string $rules = []): array
    {
        if (is_array($attribute)) {
            foreach ((array) $attribute as $innerAttribute => $innerRules) {
                $results = $this->mergeRulesForAttribute($results, $innerAttribute, $innerRules);
            }

            return $results;
        }

        return $this->mergeRulesForAttribute(
            $results,
            $attribute,
            $rules
        );
    }

    /**
     * Merge additional rules into a given attribute.
     */
    protected function mergeRulesForAttribute(array $results, string $attribute, array|object|string $rules): array
    {
        $merge = head($this->explodeRules([$rules]));

        $results[$attribute] = array_merge(
            isset($results[$attribute]) ? $this->explodeExplicitRule($results[$attribute], $attribute) : [],
            $merge
        );

        return $results;
    }

    /**
     * Extract the rule name and parameters from a rule.
     */
    public static function parse(array|object|string $rule): array
    {
        if ($rule instanceof RuleContract || $rule instanceof CompilableRules) {
            return [$rule, []];
        }

        if (is_array($rule)) {
            $rule = static::parseArrayRule($rule);
        } else {
            $rule = static::parseStringRule($rule);
        }

        $rule[0] = static::normalizeRule($rule[0]);

        return $rule;
    }

    /**
     * Parse an array based rule.
     */
    protected static function parseArrayRule(array $rule): array
    {
        return [StrCache::studly(trim(Arr::get($rule, 0, ''))), array_slice($rule, 1)];
    }

    /**
     * Parse a string based rule.
     */
    protected static function parseStringRule(string|Stringable $rule): array
    {
        $rule = (string) $rule;
        $parameters = [];

        // The format for specifying validation rules and parameters follows an
        // easy {rule}:{parameters} formatting convention. For instance the
        // rule "Max:3" states that the value may only be three letters.
        if (str_contains($rule, ':')) {
            [$rule, $parameter] = explode(':', $rule, 2);

            $parameters = static::parseParameters($rule, $parameter);
        }

        return [StrCache::studly(trim($rule)), $parameters];
    }

    /**
     * Parse a parameter list.
     */
    protected static function parseParameters(string $rule, string $parameter): array
    {
        return static::ruleIsRegex($rule) ? [$parameter] : str_getcsv($parameter, escape: '\\');
    }

    /**
     * Determine if the rule is a regular expression.
     */
    protected static function ruleIsRegex(string $rule): bool
    {
        return in_array(strtolower($rule), ['regex', 'not_regex', 'notregex'], true);
    }

    /**
     * Normalizes a rule so that we can accept short types.
     */
    protected static function normalizeRule(string $rule): string
    {
        return match ($rule) {
            'Int' => 'Integer',
            'Bool' => 'Boolean',
            default => $rule,
        };
    }

    /**
     * Expand the conditional rules in the given array of rules.
     */
    public static function filterConditionalRules(array $rules, array $data = []): array
    {
        return (new Collection($rules))->mapWithKeys(function ($attributeRules, $attribute) use ($data) {
            if (! is_array($attributeRules)
                && ! $attributeRules instanceof ConditionalRules
            ) {
                return [$attribute => $attributeRules];
            }

            if ($attributeRules instanceof ConditionalRules) {
                return [$attribute => $attributeRules->passes($data)
                    ? array_filter($attributeRules->rules($data))
                    : array_filter($attributeRules->defaultRules($data)), ];
            }

            return [$attribute => (new Collection($attributeRules))->map(function ($rule) use ($data) {
                if (! $rule instanceof ConditionalRules) {
                    return [$rule];
                }

                return $rule->passes($data) ? $rule->rules($data) : $rule->defaultRules($data);
            })->filter()->flatten(1)->values()->all()];
        })->all();
    }
}
