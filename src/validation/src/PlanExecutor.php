<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Brick\Math\BigNumber;
use DateTimeInterface;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use Hypervel\Validation\Enums\CheckType;
use Hypervel\Validation\Enums\SizeMode;
use InvalidArgumentException;

/**
 * Execute compiled AttributePlans against validation data.
 *
 * ## Architecture overview
 *
 * Hypervel's validation uses a compiled single-path execution model:
 *
 * 1. Rules are compiled into AttributePlans by RuleCompiler — each rule
 *    becomes either an InlineCheck (fast, match-dispatched) or a DelegatedCheck
 *    (calls the existing validate*() method). Plans are cached worker-lifetime
 *    by RulePlanCache for Swoole performance.
 *
 * 2. Pre-optimizations run before execution when the context is provably
 *    non-mutating: exclude_if/exclude_unless pre-evaluation (eliminates
 *    excluded attributes before the loop) and batched exists/unique DB
 *    queries (collapses N queries into 1 per table:column).
 *
 * 3. This trait executes the compiled plans through a single loop. InlineChecks
 *    use simplified gating (all are non-implicit by design) and match-dispatch.
 *    DelegatedChecks call validateAttribute() directly — no duplication of
 *    upstream logic, zero maintenance burden for delegated rules.
 *
 * ## Maintenance notes
 *
 * - Adding a new inline-eligible rule requires changes in 3 places:
 *   CheckType (enum case + ruleName), RuleCompiler::tryInline(), and
 *   executeInline() below. Forgetting executeInline() fails PHPStan.
 * - DelegatedChecks require zero changes — they call validate*() directly.
 * - executeInline() arms must match the exact behavior of their corresponding
 *   validate*() methods in ValidatesAttributes. When an upstream method
 *   changes, the inline arm must be updated to match.
 *
 * Mixed into Validator so it can call existing helpers on ValidatesAttributes
 * (sizeOf, isValidEmail, compareDates, etc.) and access validator state
 * ($this->data, $this->rules, $this->currentRule, etc.).
 */
trait PlanExecutor
{
    /**
     * Execute all compiled plans against the validation data.
     *
     * This is the ONLY execution path — every rule (inline or delegated) flows
     * through this loop. Per-check fresh reads of $value and $exists match
     * validateAttribute()'s per-rule getValue() call.
     *
     * @param array<string, AttributePlan> $compiledPlans
     */
    protected function executeCompiledPlans(array $compiledPlans): void
    {
        foreach ($compiledPlans as $attribute => $plan) {
            $attribute = (string) $attribute;

            if (isset($this->preExcludedAttributes[$attribute])) {
                $this->excludeAttribute($attribute);
                continue;
            }

            if ($this->stopOnFirstFailure && $this->messages->isNotEmpty()) {
                break;
            }

            if ($plan->sometimes && ! Arr::has($this->data, $attribute)) {
                continue;
            }

            foreach ($plan->checks as $check) {
                if ($check instanceof InlineCheck) {
                    // Fresh read per check — matches validateAttribute()'s per-rule
                    // getValue() call. Required because an earlier delegated check
                    // on the same attribute can mutate $this->data via setValue().
                    $value = $this->getValue($attribute);
                    $exists = Arr::has($this->data, $attribute);

                    if ($value instanceof UploadedFile && ! $value->isValid()
                        && $this->hasRule($attribute, array_merge($this->fileRules, $this->implicitRules))
                    ) {
                        $this->addFailure($attribute, 'uploaded', []);
                        break;
                    }

                    // All InlineChecks are non-implicit. For non-implicit rules:
                    // empty string → skip, null + nullable → skip, absent → skip.
                    if (! $exists || (is_string($value) && trim($value) === '')) {
                        continue;
                    }
                    if ($plan->nullable && $value === null) {
                        continue;
                    }

                    if (! $this->executeInline($check, $value, $attribute)) {
                        $this->addFailure($attribute, $check->getRuleName(), $check->parameters);
                    }
                } else {
                    // Delegated checks go through the normal validateAttribute() path.
                    // This handles parsing, isValidatable gating, currentRule assignment,
                    // dependent-rule parameter rewriting, and dispatch — all in one place
                    // with zero duplication of upstream logic.
                    $this->validateAttribute($attribute, $check->originalRule);
                }

                if ($this->shouldBeExcluded($attribute)) {
                    break;
                }

                if ($plan->bail && $this->messages->has($attribute)) {
                    break;
                }

                if ($this->shouldStopValidating($attribute)) {
                    break;
                }
            }
        }
    }

    /**
     * Execute an inline check against a value.
     *
     * Dispatches via exhaustive match over CheckType — no default arm. Adding
     * a CheckType case without a handler here fails PHPStan and throws
     * UnhandledMatchError at runtime. Silent passes are impossible.
     *
     * Each arm replicates the exact logic of the corresponding validate*()
     * method in ValidatesAttributes, but without parameter parsing, attribute
     * context lookups, or dynamic method dispatch.
     */
    protected function executeInline(InlineCheck $check, mixed $value, string $attribute): bool
    {
        return match ($check->type) {
            CheckType::TypeString => is_string($value),
            CheckType::TypeNumeric => is_numeric($value),
            CheckType::TypeInteger => filter_var($value, FILTER_VALIDATE_INT) !== false,
            CheckType::TypeIntegerStrict => is_int($value),
            CheckType::TypeBoolean => in_array($value, [true, false, 0, 1, '0', '1'], true),
            CheckType::TypeArray => is_array($value),

            CheckType::Email => (is_string($value) || (is_object($value) && method_exists($value, '__toString')))
                && $this->isValidEmail((string) $value),
            CheckType::Url => is_string($value) && Str::isUrl($value),
            CheckType::Ip => is_string($value) && $this->isValidIp($value),
            CheckType::Ipv4 => is_string($value) && $this->isValidIpv4($value),
            CheckType::Ipv6 => is_string($value) && $this->isValidIpv6($value),
            CheckType::Uuid => is_string($value) && $this->isValidUuid($value),
            CheckType::Ulid => is_string($value) && $this->isValidUlid($value),
            CheckType::Json => $this->executeInlineJson($value),
            CheckType::Ascii => is_string($value) && Str::isAscii($value),
            CheckType::HexColor => preg_match('/^#(?:(?:[0-9a-f]{3}){1,2}|(?:[0-9a-f]{4}){1,2})$/i', (string) $value) === 1,
            CheckType::MacAddress => is_string($value) && filter_var($value, FILTER_VALIDATE_MAC) !== false,

            CheckType::Alpha => is_string($value) && preg_match('/\A[\pL\pM]+\z/u', $value) === 1,
            CheckType::AlphaAscii => is_string($value) && preg_match('/\A[a-zA-Z]+\z/u', $value) === 1,
            CheckType::AlphaDash => (is_string($value) || is_numeric($value))
                && preg_match('/\A[\pL\pM\pN_-]+\z/u', (string) $value) > 0,
            CheckType::AlphaDashAscii => (is_string($value) || is_numeric($value))
                && preg_match('/\A[a-zA-Z0-9_-]+\z/u', (string) $value) > 0,
            CheckType::AlphaNum => (is_string($value) || is_numeric($value))
                && preg_match('/\A[\pL\pM\pN]+\z/u', (string) $value) > 0,
            CheckType::AlphaNumAscii => (is_string($value) || is_numeric($value))
                && preg_match('/\A[a-zA-Z0-9]+\z/u', (string) $value) > 0,
            CheckType::Lowercase => Str::lower((string) $value) === (string) $value,
            CheckType::Uppercase => Str::upper((string) $value) === (string) $value,

            CheckType::SizeMin => $this->compareSize($attribute, $value, $check->param['n'], '>=', $check->param['mode']),
            CheckType::SizeMax => $this->compareSize($attribute, $value, $check->param['n'], '<=', $check->param['mode']),
            CheckType::SizeExact => $this->compareSize($attribute, $value, $check->param['n'], '==', $check->param['mode']),
            CheckType::SizeBetween => $this->compareSizeBetween(
                $attribute,
                $value,
                $check->param['min'],
                $check->param['max'],
                $check->param['mode']
            ),
            CheckType::Digits => ! preg_match('/[^0-9]/', $s = (string) $value)
                && strlen($s) == $check->param,
            CheckType::DigitsBetween => $this->inlineDigitsBetween($value, $check->param),
            CheckType::MinDigits => ! preg_match('/[^0-9]/', $s = (string) $value)
                && strlen($s) >= $check->param,
            CheckType::MaxDigits => ! preg_match('/[^0-9]/', $s = (string) $value)
                && strlen($s) <= $check->param,

            CheckType::Regex => (is_string($value) || is_numeric($value))
                && preg_match($check->param, (string) $value) > 0,
            CheckType::NotRegex => (is_string($value) || is_numeric($value))
                && preg_match($check->param, (string) $value) < 1,
            CheckType::StartsWith => Str::startsWith((string) $value, $check->param),
            CheckType::EndsWith => Str::endsWith((string) $value, $check->param),
            CheckType::DoesntStartWith => ! Str::startsWith((string) $value, $check->param),
            CheckType::DoesntEndWith => ! Str::endsWith((string) $value, $check->param),

            CheckType::In => ! is_array($value) && in_array((string) $value, $check->param),
            CheckType::NotIn => ! is_array($value) && ! in_array((string) $value, $check->param),

            CheckType::IsDate => $this->isValidDate($value),
            CheckType::DateFormat => $this->inlineMatchesDateFormat($value, $check->param),
            CheckType::DateAfter => $this->inlineCompareDates($value, $check->param, '>'),
            CheckType::DateBefore => $this->inlineCompareDates($value, $check->param, '<'),
            CheckType::DateAfterOrEq => $this->inlineCompareDates($value, $check->param, '>='),
            CheckType::DateBeforeOrEq => $this->inlineCompareDates($value, $check->param, '<='),
            CheckType::DateEquals => $this->inlineCompareDates($value, $check->param, '='),

            CheckType::MultipleOf => $this->isMultipleOf($value, $check->param),
        };
    }

    /**
     * Inline JSON validation matching validateJson() behavior.
     *
     * Checks for array/null, non-stringable objects, then validates via
     * json_validate() (PHP 8.3+) or json_decode() + json_last_error().
     */
    private function executeInlineJson(mixed $value): bool
    {
        if (is_array($value) || is_null($value)) {
            return false;
        }

        if (! is_scalar($value) && ! method_exists($value, '__toString')) {
            return false;
        }

        $value = (string) $value;

        if (function_exists('json_validate')) {
            return json_validate($value);
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Compare a value's size against a threshold using BigNumber.
     *
     * Mirrors validateMax/validateMin: BigNumber::of(getSize())->compare($target).
     * Uses sizeOf() (the pre-resolved-mode variant) instead of getSize() to
     * avoid runtime rule scanning. For scientific-notation numeric strings,
     * runs ensureExponentWithinAllowedRange() to match getSize()'s behavior.
     */
    private function compareSize(string $attribute, mixed $value, string $target, string $operator, SizeMode $mode): bool
    {
        $size = BigNumber::of((string) $this->sizeOfWithExponentCheck($attribute, $value, $mode));
        $target = $this->trim($target);

        return match ($operator) {
            '>=' => $size->isGreaterThanOrEqualTo($target),
            '<=' => $size->isLessThanOrEqualTo($target),
            '==' => $size->isEqualTo($target),
            default => throw new InvalidArgumentException("Unsupported size comparison operator: {$operator}"),
        };
    }

    /**
     * Compare a value's size against a min/max range using BigNumber.
     */
    private function compareSizeBetween(string $attribute, mixed $value, string $min, string $max, SizeMode $mode): bool
    {
        $size = BigNumber::of((string) $this->sizeOfWithExponentCheck($attribute, $value, $mode));

        return $size->isGreaterThanOrEqualTo($this->trim($min)) && $size->isLessThanOrEqualTo($this->trim($max));
    }

    /**
     * Compute a value's size with exponent-range checking for numeric modes.
     *
     * For numeric-mode values, runs ensureExponentWithinAllowedRange() to
     * match getSize()'s behavior. This preserves the MathException for
     * out-of-range exponents and any custom exponent-range callbacks.
     */
    private function sizeOfWithExponentCheck(string $attribute, mixed $value, SizeMode $mode): float|int|string
    {
        if ($mode === SizeMode::Numeric && is_numeric($value)) {
            return $this->ensureExponentWithinAllowedRange($attribute, $this->trim($value));
        }

        return $this->sizeOf($value, $mode);
    }

    /**
     * Inline digits_between check matching validateDigitsBetween() behavior.
     *
     * @param array{0: int, 1: int} $range
     */
    private function inlineDigitsBetween(mixed $value, array $range): bool
    {
        $length = strlen($value = (string) $value);

        return ! preg_match('/[^0-9]/', $value)
            && $length >= $range[0] && $length <= $range[1];
    }

    /**
     * Inline date_format check matching validateDateFormat() behavior.
     *
     * Iterates over all format parameters — date_format supports multiple
     * comma-separated formats (e.g., 'date_format:Y-m-d H:i:s,H:i:s').
     *
     * @param list<string> $formats
     */
    private function inlineMatchesDateFormat(mixed $value, array $formats): bool
    {
        foreach ($formats as $format) {
            if ($this->matchesDateFormat($value, (string) $format)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inline date comparison matching compareDates() behavior.
     *
     * With format: parse both sides via getDateTimeWithOptionalFormat (as
     * in checkDateTimeOrder). Without format: parse via getDateTimestamp
     * (as in the no-format branch of compareDates).
     *
     * @param array{target: string, format: ?string} $param
     */
    private function inlineCompareDates(mixed $value, array $param, string $operator): bool
    {
        if (! is_string($value) && ! is_numeric($value) && ! $value instanceof DateTimeInterface) {
            return false;
        }

        if ($param['format'] !== null) {
            $firstDate = $this->getDateTimeWithOptionalFormat($param['format'], (string) $value);
            $secondDate = $this->getDateTimeWithOptionalFormat($param['format'], $param['target']);

            return ($firstDate && $secondDate) && $this->compare($firstDate, $secondDate, $operator);
        }

        return $this->compare(
            $this->getDateTimestamp($value),
            $this->getDateTimestamp($param['target']),
            $operator,
        );
    }
}
