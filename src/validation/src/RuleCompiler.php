<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Validation\Enums\CheckType;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;

/**
 * Compile pipe-string or array rules into an AttributePlan.
 *
 * Each rule part becomes either an InlineCheck (fast, match-dispatched) or a
 * DelegatedCheck (calls existing validate*() methods). The compiler resolves
 * sibling context (numeric semantics, date format, array presence) to bake
 * compile-time decisions into check params.
 */
final class RuleCompiler
{
    /**
     * Compile a per-attribute rule array into an AttributePlan with inlining.
     *
     * Used for the base Validator class. Subclasses use compileAllDelegated().
     *
     * @param list<mixed> $rules As produced by ValidationRuleParser::explode()
     * @param list<string> $numericRules Rules which activate numeric size semantics
     */
    public static function compile(array $rules, array $numericRules): AttributePlan
    {
        $plan = new AttributePlan;
        $parsedRules = array_map(
            static fn (mixed $rule): array => ValidationRuleParser::parse($rule),
            $rules,
        );

        foreach ($parsedRules as [, $parameters]) {
            if (array_any($parameters, static fn (mixed $parameter): bool => ! is_scalar($parameter))) {
                foreach ($rules as $index => $rule) {
                    self::compileRuleDelegated($rule, $plan, $parsedRules[$index]);
                }

                return $plan;
            }
        }

        $context = self::collectContext($parsedRules, $numericRules);

        foreach ($rules as $index => $rule) {
            self::compileRule($rule, $parsedRules[$index], $plan, $context);
        }

        return $plan;
    }

    /**
     * Compile all rules as DelegatedCheck (no inlining).
     *
     * Used for Validator subclasses. Retains meta flags for attribute-level
     * gating while keeping every declared rule available to delegated execution.
     *
     * @param list<mixed> $rules As produced by ValidationRuleParser::explode()
     */
    public static function compileAllDelegated(array $rules): AttributePlan
    {
        $plan = new AttributePlan;

        foreach ($rules as $rule) {
            self::compileRuleDelegated($rule, $plan);
        }

        return $plan;
    }

    /**
     * Pre-scan all rule parts to collect compile-time context.
     *
     * @param list<array{0: mixed, 1: array<int, mixed>}> $parsedRules
     * @param list<string> $numericRules
     * @return array{numeric: bool, dateFormat: ?string, hasSiblingArrayRule: bool}
     */
    private static function collectContext(array $parsedRules, array $numericRules): array
    {
        $numeric = false;
        $dateFormat = null;
        $hasSiblingArrayRule = false;

        foreach ($parsedRules as [$parsedName, $parsedParameters]) {
            if (! is_string($parsedName)) {
                continue;
            }

            if (in_array($parsedName, $numericRules, true)) {
                $numeric = true;
            }

            if ($dateFormat === null && $parsedName === 'DateFormat' && isset($parsedParameters[0])) {
                $dateFormat = (string) $parsedParameters[0];
            }

            if ($parsedName === 'Array') {
                $hasSiblingArrayRule = true;
            }
        }

        return [
            'numeric' => $numeric,
            'dateFormat' => $dateFormat,
            'hasSiblingArrayRule' => $hasSiblingArrayRule,
        ];
    }

    /**
     * Compile a single rule into the plan, attempting to inline where possible.
     *
     * RuleContract objects remain intact. Other rules are parsed, flags are
     * resolved, and eligible string rules are compiled as InlineCheck.
     * Everything else becomes a DelegatedCheck.
     *
     * @param array{0: mixed, 1: array<int, mixed>} $parsedRule
     * @param array{numeric: bool, dateFormat: ?string, hasSiblingArrayRule: bool} $context
     */
    private static function compileRule(mixed $rule, array $parsedRule, AttributePlan $plan, array $context): void
    {
        if ($rule instanceof RuleContract) {
            $plan->checks[] = new DelegatedCheck(
                ruleName: '',
                parameters: [],
                originalRule: $rule,
            );
            return;
        }

        [$ruleName, $parameters] = $parsedRule;

        if (! is_string($ruleName) || $ruleName === '') {
            return;
        }

        // The exact base plan needs nullable/bail/sometimes only as meta-flags.
        // compileRuleDelegated() also emits them so subclass hooks still run.
        if ($ruleName === 'Nullable') {
            $plan->nullable = true;
            return;
        }
        if ($ruleName === 'Bail') {
            $plan->bail = true;
            return;
        }
        if ($ruleName === 'Sometimes') {
            $plan->sometimes = true;
            return;
        }

        $inline = self::tryInline($ruleName, $parameters, $context);
        if ($inline !== null) {
            $plan->checks[] = $inline;
            return;
        }

        self::appendDelegatedCheck(
            $plan,
            ruleName: $ruleName,
            parameters: $parameters,
            originalRule: $rule,
        );
    }

    /**
     * Compile a single rule as DelegatedCheck only (no inlining).
     *
     * Handles the same input forms and flag resolution as compileRule() but
     * skips tryInline() so every declared rule becomes a DelegatedCheck.
     *
     * @param null|array{0: mixed, 1: array<int, mixed>} $parsedRule
     */
    private static function compileRuleDelegated(mixed $rule, AttributePlan $plan, ?array $parsedRule = null): void
    {
        if ($rule instanceof RuleContract) {
            $plan->checks[] = new DelegatedCheck(
                ruleName: '',
                parameters: [],
                originalRule: $rule,
            );
            return;
        }

        [$ruleName, $parameters] = $parsedRule ?? ValidationRuleParser::parse($rule);

        if (! is_string($ruleName) || $ruleName === '') {
            return;
        }

        if ($ruleName === 'Nullable') {
            $plan->nullable = true;
        } elseif ($ruleName === 'Bail') {
            $plan->bail = true;
        } elseif ($ruleName === 'Sometimes') {
            $plan->sometimes = true;
        }

        self::appendDelegatedCheck(
            $plan,
            ruleName: $ruleName,
            parameters: $parameters,
            originalRule: $rule,
        );
    }

    /**
     * Append a parsed delegated check to the plan.
     */
    private static function appendDelegatedCheck(
        AttributePlan $plan,
        string $ruleName,
        array $parameters,
        mixed $originalRule,
    ): void {
        $check = new DelegatedCheck($ruleName, $parameters, $originalRule);
        $plan->checks[] = $check;

        if (self::canConsumePrecomputedPresenceLookup($check)) {
            ++$plan->databasePresenceCheckCount;
        }
    }

    /**
     * Determine if a check can consume a precomputed database-presence lookup.
     */
    private static function canConsumePrecomputedPresenceLookup(DelegatedCheck $check): bool
    {
        if (! $check->parametersAreScalar
            || ($check->ruleName !== 'Exists' && $check->ruleName !== 'Unique')
        ) {
            return false;
        }

        return ! (($check->originalRule instanceof Exists || $check->originalRule instanceof Unique)
            && $check->originalRule->queryCallbacks() !== []);
    }

    /**
     * Attempt to compile a parsed string rule as an InlineCheck.
     *
     * Returns null if the rule is not inline-eligible (it will become a DelegatedCheck).
     *
     * @param array{numeric: bool, dateFormat: ?string, hasSiblingArrayRule: bool} $context
     */
    private static function tryInline(string $ruleName, array $parameters, array $context): ?InlineCheck
    {
        return match ($ruleName) {
            'String' => new InlineCheck(CheckType::TypeString),
            'Numeric' => $parameters === [] ? new InlineCheck(CheckType::TypeNumeric) : null,
            'Integer' => $parameters === []
                ? new InlineCheck(CheckType::TypeInteger)
                : (isset($parameters[0]) && $parameters[0] === 'strict'
                    ? new InlineCheck(CheckType::TypeIntegerStrict, parameters: ['strict'])
                    : null),
            'Boolean' => $parameters === [] ? new InlineCheck(CheckType::TypeBoolean) : null,
            'Array' => $parameters === [] ? new InlineCheck(CheckType::TypeArray) : null,

            'Email' => $parameters === [] ? new InlineCheck(CheckType::Email) : null,
            'Url' => $parameters === [] ? new InlineCheck(CheckType::Url) : null,
            'Ip' => new InlineCheck(CheckType::Ip),
            'Ipv4' => new InlineCheck(CheckType::Ipv4),
            'Ipv6' => new InlineCheck(CheckType::Ipv6),
            'Uuid' => $parameters === [] ? new InlineCheck(CheckType::Uuid) : null,
            'Ulid' => new InlineCheck(CheckType::Ulid),
            'Json' => new InlineCheck(CheckType::Json),
            'Ascii' => new InlineCheck(CheckType::Ascii),
            'HexColor' => new InlineCheck(CheckType::HexColor),
            'MacAddress' => new InlineCheck(CheckType::MacAddress),

            'Alpha' => isset($parameters[0]) && $parameters[0] === 'ascii'
                ? new InlineCheck(CheckType::AlphaAscii, parameters: ['ascii'])
                : new InlineCheck(CheckType::Alpha),
            'AlphaDash' => isset($parameters[0]) && $parameters[0] === 'ascii'
                ? new InlineCheck(CheckType::AlphaDashAscii, parameters: ['ascii'])
                : new InlineCheck(CheckType::AlphaDash),
            'AlphaNum' => isset($parameters[0]) && $parameters[0] === 'ascii'
                ? new InlineCheck(CheckType::AlphaNumAscii, parameters: ['ascii'])
                : new InlineCheck(CheckType::AlphaNum),
            'Lowercase' => new InlineCheck(CheckType::Lowercase),
            'Uppercase' => new InlineCheck(CheckType::Uppercase),

            'Min' => self::tryInlineSize(CheckType::SizeMin, $parameters, $context),
            'Max' => self::tryInlineSize(CheckType::SizeMax, $parameters, $context),
            'Size' => self::tryInlineSize(CheckType::SizeExact, $parameters, $context),
            'Between' => self::tryInlineSizeBetween($parameters, $context),
            'Digits' => self::hasExactIntegerParameters($parameters, 1)
                ? new InlineCheck(CheckType::Digits, (int) $parameters[0], parameters: $parameters)
                : null,
            'DigitsBetween' => self::hasExactIntegerParameters($parameters, 2)
                ? new InlineCheck(CheckType::DigitsBetween, [(int) $parameters[0], (int) $parameters[1]], parameters: $parameters)
                : null,
            'MinDigits' => self::hasExactIntegerParameters($parameters, 1)
                ? new InlineCheck(CheckType::MinDigits, (int) $parameters[0], parameters: $parameters)
                : null,
            'MaxDigits' => self::hasExactIntegerParameters($parameters, 1)
                ? new InlineCheck(CheckType::MaxDigits, (int) $parameters[0], parameters: $parameters)
                : null,

            'Regex' => isset($parameters[0])
                ? new InlineCheck(CheckType::Regex, $parameters[0], parameters: $parameters)
                : null,
            'NotRegex' => isset($parameters[0])
                ? new InlineCheck(CheckType::NotRegex, $parameters[0], parameters: $parameters)
                : null,
            'StartsWith' => new InlineCheck(CheckType::StartsWith, $parameters, parameters: $parameters),
            'EndsWith' => new InlineCheck(CheckType::EndsWith, $parameters, parameters: $parameters),
            'DoesntStartWith' => new InlineCheck(CheckType::DoesntStartWith, $parameters, parameters: $parameters),
            'DoesntEndWith' => new InlineCheck(CheckType::DoesntEndWith, $parameters, parameters: $parameters),

            'In' => $context['hasSiblingArrayRule']
                ? null
                : new InlineCheck(
                    CheckType::In,
                    array_map(
                        static fn (mixed $parameter): string => (string) $parameter,
                        $parameters,
                    ),
                    parameters: $parameters,
                ),
            'NotIn' => $context['hasSiblingArrayRule']
                ? null
                : new InlineCheck(
                    CheckType::NotIn,
                    array_map(
                        static fn (mixed $parameter): string => (string) $parameter,
                        $parameters,
                    ),
                    parameters: $parameters,
                ),

            'Date' => new InlineCheck(CheckType::IsDate),
            'DateFormat' => isset($parameters[0])
                ? new InlineCheck(CheckType::DateFormat, $parameters, parameters: $parameters)
                : null,
            'After' => self::tryInlineDate(CheckType::DateAfter, $parameters, $context),
            'Before' => self::tryInlineDate(CheckType::DateBefore, $parameters, $context),
            'AfterOrEqual' => self::tryInlineDate(CheckType::DateAfterOrEq, $parameters, $context),
            'BeforeOrEqual' => self::tryInlineDate(CheckType::DateBeforeOrEq, $parameters, $context),
            'DateEquals' => self::tryInlineDate(CheckType::DateEquals, $parameters, $context),

            'MultipleOf' => isset($parameters[0]) && is_numeric($parameters[0])
                ? new InlineCheck(CheckType::MultipleOf, $parameters[0], parameters: $parameters)
                : null,

            default => null,
        };
    }

    /**
     * Try to inline a min/max/size rule as a size check.
     *
     * Returns null when there's no numeric parameter.
     */
    private static function tryInlineSize(CheckType $type, array $parameters, array $context): ?InlineCheck
    {
        if (! isset($parameters[0]) || ! is_numeric($parameters[0])) {
            return null;
        }

        return new InlineCheck(
            $type,
            [
                'numeric' => $context['numeric'],
                'threshold' => self::compileSizeThreshold($parameters[0]),
            ],
            parameters: $parameters,
        );
    }

    /**
     * Try to inline a between rule as a size-between check.
     *
     * Returns null when the parameter count is wrong or bounds aren't numeric.
     */
    private static function tryInlineSizeBetween(array $parameters, array $context): ?InlineCheck
    {
        if (count($parameters) !== 2
            || ! is_numeric($parameters[0])
            || ! is_numeric($parameters[1])
        ) {
            return null;
        }

        return new InlineCheck(
            CheckType::SizeBetween,
            [
                'numeric' => $context['numeric'],
                'minimum' => self::compileSizeThreshold($parameters[0]),
                'maximum' => self::compileSizeThreshold($parameters[1]),
            ],
            parameters: $parameters,
        );
    }

    /**
     * Normalize and classify a size threshold for execution.
     *
     * @return array{raw: string, integer: ?int}
     */
    private static function compileSizeThreshold(mixed $threshold): array
    {
        $rawThreshold = trim((string) $threshold);
        $integerThreshold = filter_var($rawThreshold, FILTER_VALIDATE_INT);

        return [
            'raw' => $rawThreshold,
            'integer' => $integerThreshold === false ? null : $integerThreshold,
        ];
    }

    /**
     * Determine if the parameters are exact integer strings.
     */
    private static function hasExactIntegerParameters(array $parameters, int $count): bool
    {
        return count($parameters) === $count
            && array_all(
                $parameters,
                static fn (mixed $parameter): bool => is_string($parameter)
                    && preg_match('/^[+-]?\d+$/D', $parameter) === 1,
            );
    }

    /**
     * Try to inline a date comparison rule.
     *
     * Bakes the literal target string and sibling date_format (if any) into
     * the check. Returns null when the target looks like a field reference —
     * those must go through DelegatedCheck where compareDates() resolves the
     * referenced attribute's value.
     *
     * @param array{numeric: bool, dateFormat: ?string, hasSiblingArrayRule: bool} $context
     */
    private static function tryInlineDate(CheckType $type, array $parameters, array $context): ?InlineCheck
    {
        if (! isset($parameters[0])) {
            return null;
        }

        if (ValidationRuleParser::looksLikeDateFieldReference($parameters[0])) {
            return null;
        }

        return new InlineCheck(
            $type,
            ['target' => $parameters[0], 'format' => $context['dateFormat']],
            parameters: $parameters,
        );
    }
}
