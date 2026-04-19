<?php

declare(strict_types=1);

namespace Hypervel\Validation\Enums;

/**
 * Enumeration of fast-path eligible validation check types.
 *
 * Each case corresponds to a rule token that RuleCompiler can emit as an
 * InlineCheck and that PlanExecutor must handle via its exhaustive match.
 *
 * Adding a case requires:
 *   1. A compiler case in RuleCompiler::tryInline() that emits this CheckType
 *   2. A runner arm in PlanExecutor::executeInline() that handles it
 *   3. A rule name mapping in ruleName() below
 *
 * Forgetting (2) fails PHPStan (exhaustive match) or throws UnhandledMatchError
 * at runtime. Forgetting (3) fails PHPStan (exhaustive match, no default arm).
 * Forgetting (1) is harmless — the rule simply flows through DelegatedCheck.
 */
enum CheckType
{
    // Type checks
    case TypeString;
    case TypeNumeric;
    case TypeInteger;
    case TypeIntegerStrict;
    case TypeBoolean;
    case TypeArray;

    // Format checks
    case Email;
    case Url;
    case Ip;
    case Ipv4;
    case Ipv6;
    case Uuid;
    case Ulid;
    case Json;
    case Ascii;
    case HexColor;
    case MacAddress;

    // Character class
    case Alpha;
    case AlphaAscii;
    case AlphaDash;
    case AlphaDashAscii;
    case AlphaNum;
    case AlphaNumAscii;
    case Lowercase;
    case Uppercase;

    // Size
    case SizeMin;
    case SizeMax;
    case SizeBetween;
    case SizeExact;
    case Digits;
    case DigitsBetween;
    case MinDigits;
    case MaxDigits;

    // Pattern
    case Regex;
    case NotRegex;
    case StartsWith;
    case EndsWith;
    case DoesntStartWith;
    case DoesntEndWith;

    // Set
    case In;
    case NotIn;

    // Date
    case IsDate;
    case DateFormat;
    case DateAfter;
    case DateBefore;
    case DateAfterOrEq;
    case DateBeforeOrEq;
    case DateEquals;

    // Numeric-specific
    case MultipleOf;

    /**
     * Get the Laravel rule name for addFailure() error message lookup.
     *
     * Multiple cases can map to the same rule name (e.g. TypeInteger and
     * TypeIntegerStrict both map to 'Integer') because distinct execution
     * checks can share the same translation message key.
     *
     * Exhaustive — no default arm. Adding a case without a mapping here
     * fails PHPStan and throws UnhandledMatchError at runtime.
     */
    public function ruleName(): string
    {
        return match ($this) {
            self::TypeString => 'String',
            self::TypeNumeric => 'Numeric',
            self::TypeInteger,
            self::TypeIntegerStrict => 'Integer',
            self::TypeBoolean => 'Boolean',
            self::TypeArray => 'Array',

            self::Email => 'Email',
            self::Url => 'Url',
            self::Ip => 'Ip',
            self::Ipv4 => 'Ipv4',
            self::Ipv6 => 'Ipv6',
            self::Uuid => 'Uuid',
            self::Ulid => 'Ulid',
            self::Json => 'Json',
            self::Ascii => 'Ascii',
            self::HexColor => 'HexColor',
            self::MacAddress => 'MacAddress',

            self::Alpha,
            self::AlphaAscii => 'Alpha',
            self::AlphaDash,
            self::AlphaDashAscii => 'AlphaDash',
            self::AlphaNum,
            self::AlphaNumAscii => 'AlphaNum',
            self::Lowercase => 'Lowercase',
            self::Uppercase => 'Uppercase',

            self::SizeMin => 'Min',
            self::SizeMax => 'Max',
            self::SizeBetween => 'Between',
            self::SizeExact => 'Size',
            self::Digits => 'Digits',
            self::DigitsBetween => 'DigitsBetween',
            self::MinDigits => 'MinDigits',
            self::MaxDigits => 'MaxDigits',

            self::Regex => 'Regex',
            self::NotRegex => 'NotRegex',
            self::StartsWith => 'StartsWith',
            self::EndsWith => 'EndsWith',
            self::DoesntStartWith => 'DoesntStartWith',
            self::DoesntEndWith => 'DoesntEndWith',

            self::In => 'In',
            self::NotIn => 'NotIn',

            self::IsDate => 'Date',
            self::DateFormat => 'DateFormat',
            self::DateAfter => 'After',
            self::DateBefore => 'Before',
            self::DateAfterOrEq => 'AfterOrEqual',
            self::DateBeforeOrEq => 'BeforeOrEqual',
            self::DateEquals => 'DateEquals',

            self::MultipleOf => 'MultipleOf',
        };
    }
}
