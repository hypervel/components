<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Contracts\Validation\ImplicitRule;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Validation\Enums\CheckType;
use Hypervel\Validation\Enums\SizeMode;

/**
 * Compile pipe-string or array rules into an AttributePlan.
 *
 * Each rule part becomes either an InlineCheck (fast, match-dispatched) or a
 * DelegatedCheck (calls existing validate*() methods). The compiler resolves
 * sibling context (size mode, date format, array presence) to bake compile-time
 * decisions into check params.
 */
final class RuleCompiler
{
    /**
     * Compile a per-attribute rule array into an AttributePlan with inlining.
     *
     * Used for the base Validator class. Subclasses use compileAllDelegated().
     *
     * @param list<mixed> $rules As produced by ValidationRuleParser::explode()
     */
    public static function compile(array $rules): AttributePlan
    {
        $plan = new AttributePlan;

        $context = self::collectContext($rules);
        $plan->sizeMode = $context['sizeMode'];

        foreach ($rules as $rule) {
            self::compileRule($rule, $plan, $context);
        }

        return $plan;
    }

    /**
     * Compile all rules as DelegatedCheck (no inlining).
     *
     * Used for Validator subclasses which may override validate*() methods.
     * Shares the same flag pre-resolution so the execution loop's attribute-level
     * logic (sometimes, excluded) still works.
     *
     * @param list<mixed> $rules As produced by ValidationRuleParser::explode()
     */
    public static function compileAllDelegated(array $rules): AttributePlan
    {
        $plan = new AttributePlan;

        $context = self::collectContext($rules);
        $plan->sizeMode = $context['sizeMode'];

        foreach ($rules as $rule) {
            self::compileRuleDelegated($rule, $plan);
        }

        return $plan;
    }

    /**
     * Pre-scan all rule parts to collect compile-time context.
     *
     * @return array{sizeMode: ?SizeMode, dateFormat: ?string, hasSiblingArrayRule: bool}
     */
    private static function collectContext(array $rules): array
    {
        /** @var list<SizeMode> $modes */
        $modes = [];
        $dateFormat = null;
        $hasSiblingArrayRule = false;

        foreach ($rules as $rule) {
            [$parsedName, $parsedParams] = ValidationRuleParser::parse($rule);

            if (! is_string($parsedName)) {
                continue;
            }

            if (($mode = self::resolveSizeMode($parsedName)) !== null) {
                $modes[] = $mode;
            }

            if ($dateFormat === null && $parsedName === 'DateFormat' && isset($parsedParams[0])) {
                $dateFormat = $parsedParams[0];
            }

            if ($parsedName === 'Array') {
                $hasSiblingArrayRule = true;
            }
        }

        $uniqueModes = array_values(array_unique($modes, SORT_REGULAR));
        $sizeMode = count($uniqueModes) === 1 ? $uniqueModes[0] : null;

        return [
            'sizeMode' => $sizeMode,
            'dateFormat' => $dateFormat,
            'hasSiblingArrayRule' => $hasSiblingArrayRule,
        ];
    }

    /**
     * Map a parsed rule name to the SizeMode it implies.
     *
     * Returns null for rules that don't imply a size mode.
     */
    private static function resolveSizeMode(string $parsedName): ?SizeMode
    {
        return match ($parsedName) {
            'String' => SizeMode::String,
            'Numeric', 'Integer' => SizeMode::Numeric,
            'Array' => SizeMode::Array,
            'File', 'Image' => SizeMode::File,
            default => null,
        };
    }

    /**
     * Compile a single rule into the plan, attempting to inline where possible.
     *
     * Handles four input forms: RuleContract objects, Exists/Unique Stringable
     * objects, raw non-string values, and string rule tokens. String rules are
     * parsed, flags are resolved, and eligible rules are compiled as InlineCheck.
     * Everything else becomes a DelegatedCheck.
     *
     * @param array{sizeMode: ?SizeMode, dateFormat: ?string, hasSiblingArrayRule: bool} $context
     */
    private static function compileRule(mixed $rule, AttributePlan $plan, array $context): void
    {
        if ($rule instanceof RuleContract) {
            if ($rule instanceof ImplicitRule) {
                $plan->hasImplicitRule = true;
            }
            $plan->checks[] = new DelegatedCheck(
                ruleName: '',
                parameters: [],
                ruleObject: $rule,
                originalRule: $rule,
            );
            return;
        }

        if ($rule instanceof Rules\Exists || $rule instanceof Rules\Unique) {
            [$ruleName, $parameters] = ValidationRuleParser::parse($rule);
            $plan->checks[] = new DelegatedCheck(
                ruleName: $ruleName,
                parameters: $parameters,
                ruleObject: $rule,
                originalRule: $rule,
            );
            return;
        }

        if (! is_string($rule)) {
            [$parsedName, $parsedParams] = ValidationRuleParser::parse($rule);
            if (! is_string($parsedName) || $parsedName === '') {
                return;
            }
            $plan->checks[] = new DelegatedCheck(
                ruleName: $parsedName,
                parameters: $parsedParams,
                originalRule: $rule,
            );
            return;
        }

        [$ruleName, $parameters] = ValidationRuleParser::parse($rule);

        if ($ruleName === '') {
            return;
        }

        // required sets a flag AND falls through to produce a DelegatedCheck.
        // It is a real validation rule whose validateRequired() can fail.
        if ($ruleName === 'Required') {
            $plan->required = true;
            $plan->hasImplicitRule = true;
        }

        // nullable/bail/sometimes are pure meta-flags — their validate*() methods
        // are no-ops returning true, so they don't need checks.
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

        if (self::isImplicitRule($ruleName)) {
            $plan->hasImplicitRule = true;
        }

        $plan->checks[] = new DelegatedCheck(
            ruleName: $ruleName,
            parameters: $parameters,
            originalRule: $rule,
        );
    }

    /**
     * Compile a single rule as DelegatedCheck only (no inlining).
     *
     * Used by compileAllDelegated() for Validator subclasses. Handles the
     * same input forms and flag resolution as compileRule() but skips the
     * tryInline() step — everything becomes a DelegatedCheck.
     */
    private static function compileRuleDelegated(mixed $rule, AttributePlan $plan): void
    {
        if ($rule instanceof RuleContract) {
            if ($rule instanceof ImplicitRule) {
                $plan->hasImplicitRule = true;
            }
            $plan->checks[] = new DelegatedCheck(
                ruleName: '',
                parameters: [],
                ruleObject: $rule,
                originalRule: $rule,
            );
            return;
        }

        if ($rule instanceof Rules\Exists || $rule instanceof Rules\Unique) {
            [$ruleName, $parameters] = ValidationRuleParser::parse($rule);
            $plan->checks[] = new DelegatedCheck(
                ruleName: $ruleName,
                parameters: $parameters,
                ruleObject: $rule,
                originalRule: $rule,
            );
            return;
        }

        if (! is_string($rule)) {
            [$parsedName, $parsedParams] = ValidationRuleParser::parse($rule);
            if (! is_string($parsedName) || $parsedName === '') {
                return;
            }
            $plan->checks[] = new DelegatedCheck(
                ruleName: $parsedName,
                parameters: $parsedParams,
                originalRule: $rule,
            );
            return;
        }

        [$ruleName, $parameters] = ValidationRuleParser::parse($rule);

        if ($ruleName === '') {
            return;
        }

        if ($ruleName === 'Required') {
            $plan->required = true;
            $plan->hasImplicitRule = true;
        }
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

        if (self::isImplicitRule($ruleName)) {
            $plan->hasImplicitRule = true;
        }

        $plan->checks[] = new DelegatedCheck(
            ruleName: $ruleName,
            parameters: $parameters,
            originalRule: $rule,
        );
    }

    /**
     * Attempt to compile a parsed string rule as an InlineCheck.
     *
     * Returns null if the rule is not inline-eligible (it will become a DelegatedCheck).
     *
     * @param array{sizeMode: ?SizeMode, dateFormat: ?string, hasSiblingArrayRule: bool} $context
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
            'Digits' => isset($parameters[0])
                ? new InlineCheck(CheckType::Digits, (int) $parameters[0], parameters: $parameters)
                : null,
            'DigitsBetween' => count($parameters) === 2
                ? new InlineCheck(CheckType::DigitsBetween, [(int) $parameters[0], (int) $parameters[1]], parameters: $parameters)
                : null,
            'MinDigits' => isset($parameters[0])
                ? new InlineCheck(CheckType::MinDigits, (int) $parameters[0], parameters: $parameters)
                : null,
            'MaxDigits' => isset($parameters[0])
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
                : new InlineCheck(CheckType::In, $parameters, parameters: $parameters),
            'NotIn' => $context['hasSiblingArrayRule']
                ? null
                : new InlineCheck(CheckType::NotIn, $parameters, parameters: $parameters),

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
     * Returns null when there's no parameter, the parameter isn't numeric,
     * or the size mode couldn't be resolved (ambiguous sibling type rules).
     * Thresholds are stored as raw numeric strings so BigNumber comparison
     * preserves decimal precision.
     */
    private static function tryInlineSize(CheckType $type, array $parameters, array $context): ?InlineCheck
    {
        if (! isset($parameters[0]) || ! is_numeric($parameters[0]) || $context['sizeMode'] === null) {
            return null;
        }

        return new InlineCheck(
            $type,
            ['n' => $parameters[0], 'mode' => $context['sizeMode']],
            parameters: $parameters,
        );
    }

    /**
     * Try to inline a between rule as a size-between check.
     *
     * Returns null when the parameter count is wrong, bounds aren't numeric,
     * or the size mode couldn't be resolved.
     */
    private static function tryInlineSizeBetween(array $parameters, array $context): ?InlineCheck
    {
        if (count($parameters) !== 2
            || ! is_numeric($parameters[0])
            || ! is_numeric($parameters[1])
            || $context['sizeMode'] === null
        ) {
            return null;
        }

        return new InlineCheck(
            CheckType::SizeBetween,
            ['min' => $parameters[0], 'max' => $parameters[1], 'mode' => $context['sizeMode']],
            parameters: $parameters,
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
     * @param array{sizeMode: ?SizeMode, dateFormat: ?string, hasSiblingArrayRule: bool} $context
     */
    private static function tryInlineDate(CheckType $type, array $parameters, array $context): ?InlineCheck
    {
        if (! isset($parameters[0])) {
            return null;
        }

        if (self::looksLikeFieldRef($parameters[0])) {
            return null;
        }

        return new InlineCheck(
            $type,
            ['target' => $parameters[0], 'format' => $context['dateFormat']],
            parameters: $parameters,
        );
    }

    /**
     * Determine if a string looks like a field reference rather than a date literal.
     *
     * Conservative — in ambiguous cases returns true (treat as field ref) so the
     * rule goes through DelegatedCheck where compareDates() resolves the reference.
     */
    private static function looksLikeFieldRef(string $arg): bool
    {
        $literals = ['today', 'yesterday', 'tomorrow', 'now'];
        if (in_array(strtolower($arg), $literals, true)) {
            return false;
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z0-9_*]+)*$/', $arg) === 1;
    }

    /**
     * Determine if a rule name identifies an implicit rule.
     *
     * Implicit rules run even when the attribute is absent or empty. This
     * list mirrors Validator::$implicitRules and is used to set the
     * hasImplicitRule flag on the compiled plan.
     */
    private static function isImplicitRule(string $ruleName): bool
    {
        return in_array($ruleName, [
            'Accepted', 'AcceptedIf', 'Declined', 'DeclinedIf',
            'Filled',
            'Missing', 'MissingIf', 'MissingUnless', 'MissingWith', 'MissingWithAll',
            'Present', 'PresentIf', 'PresentUnless', 'PresentWith', 'PresentWithAll',
            'Required', 'RequiredIf', 'RequiredIfAccepted', 'RequiredIfDeclined',
            'RequiredUnless', 'RequiredWith', 'RequiredWithAll',
            'RequiredWithout', 'RequiredWithoutAll',
        ], true);
    }
}
