<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Contracts\Validation\ImplicitRule;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\AttributePlan;
use Hypervel\Validation\ClosureValidationRule;
use Hypervel\Validation\DelegatedCheck;
use Hypervel\Validation\Enums\CheckType;
use Hypervel\Validation\InlineCheck;
use Hypervel\Validation\RuleCompiler;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;
use Stringable;

class ValidationRuleCompilerTest extends TestCase
{
    private const array NUMERIC_RULES = ['Numeric', 'Integer', 'Decimal'];

    public function testRequiredProducesDelegatedCheck(): void
    {
        $plan = $this->compile(['required']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame('Required', $plan->checks[0]->ruleName);
    }

    public function testNullableSetsFlag()
    {
        $plan = $this->compile(['nullable']);

        $this->assertTrue($plan->nullable);
        $this->assertCount(0, $plan->checks);
    }

    public function testBailSetsFlag()
    {
        $plan = $this->compile(['bail']);

        $this->assertTrue($plan->bail);
        $this->assertCount(0, $plan->checks);
    }

    public function testSometimesSetsFlag()
    {
        $plan = $this->compile(['sometimes']);

        $this->assertTrue($plan->sometimes);
        $this->assertCount(0, $plan->checks);
    }

    public function testEmptyRuleStringProducesNoCheck()
    {
        $plan = $this->compile(['']);

        $this->assertCount(0, $plan->checks);
    }

    public function testStringInlinesCorrectly()
    {
        $plan = $this->compile(['string']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::TypeString, $plan->checks[0]->type);
    }

    public function testNumericBareInlines()
    {
        $plan = $this->compile(['numeric']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::TypeNumeric, $plan->checks[0]->type);
    }

    public function testNumericStrictDelegates()
    {
        $plan = $this->compile(['numeric:strict']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame('Numeric', $plan->checks[0]->ruleName);
    }

    public function testBooleanBareInlines()
    {
        $plan = $this->compile(['boolean']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::TypeBoolean, $plan->checks[0]->type);
    }

    public function testBooleanStrictDelegates()
    {
        $plan = $this->compile(['boolean:strict']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testIntegerBareInlines()
    {
        $plan = $this->compile(['integer']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::TypeInteger, $plan->checks[0]->type);
    }

    public function testIntegerStrictInlines()
    {
        $plan = $this->compile(['integer:strict']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::TypeIntegerStrict, $plan->checks[0]->type);
    }

    public function testUuidBareInlines()
    {
        $plan = $this->compile(['uuid']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::Uuid, $plan->checks[0]->type);
    }

    public function testUuidWithVersionDelegates()
    {
        $plan = $this->compile(['uuid:4']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testEmailBareInlines()
    {
        $plan = $this->compile(['email']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::Email, $plan->checks[0]->type);
    }

    public function testEmailWithParamsDelegates()
    {
        $plan = $this->compile(['email:rfc,dns']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testUrlBareInlines()
    {
        $plan = $this->compile(['url']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
    }

    public function testUrlWithParamsDelegates()
    {
        $plan = $this->compile(['url:http,https']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testArrayBareInlines()
    {
        $plan = $this->compile(['array']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::TypeArray, $plan->checks[0]->type);
    }

    public function testArrayWithKeysDelegates()
    {
        $plan = $this->compile(['array:name,email']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testSizeRulesInlineWithoutATypeSibling(): void
    {
        $plan = $this->compile(['max:255']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::SizeMax, $plan->checks[0]->type);
        $this->assertFalse($plan->checks[0]->param['numeric']);
        $this->assertSame(['raw' => '255', 'integer' => 255], $plan->checks[0]->param['threshold']);
    }

    public function testNumericSiblingActivatesNumericSizeSemantics(): void
    {
        $plan = $this->compile(['numeric', 'max:255']);

        $this->assertInstanceOf(InlineCheck::class, $plan->checks[1]);
        $this->assertTrue($plan->checks[1]->param['numeric']);
    }

    public function testNumericSemanticsRemainActiveWithConflictingTypeSiblings(): void
    {
        $plan = $this->compile(['numeric', 'string', 'max:10']);

        $this->assertCount(3, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[2]);
        $this->assertTrue($plan->checks[2]->param['numeric']);
    }

    public function testBetweenStoresNumericSemanticsAndClassifiedThresholds(): void
    {
        $plan = $this->compile(['decimal:2', 'between:1.5,100']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[1]);
        $this->assertSame(CheckType::SizeBetween, $plan->checks[1]->type);
        $this->assertTrue($plan->checks[1]->param['numeric']);
        $this->assertSame(['raw' => '1.5', 'integer' => null], $plan->checks[1]->param['minimum']);
        $this->assertSame(['raw' => '100', 'integer' => 100], $plan->checks[1]->param['maximum']);
    }

    public function testInWithoutSiblingArrayInlines()
    {
        $plan = $this->compile(['in:a,b,c']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::In, $plan->checks[0]->type);
    }

    public function testInWithSiblingArrayDelegates()
    {
        $plan = $this->compile(['array', 'in:a,b,c']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[1]);
        $this->assertSame('In', $plan->checks[1]->ruleName);
    }

    public function testNotInWithSiblingArrayDelegates()
    {
        $plan = $this->compile(['array', 'not_in:a,b,c']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[1]);
    }

    public function testArrayFormSiblingArrayTriggersDelegation()
    {
        // Array-form ['array'] must be detected as a sibling array rule,
        // causing 'in' to delegate (uses array_diff branch in validateIn).
        $plan = $this->compile([['array'], 'in:a,b,c']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[1]);
        $this->assertSame('In', $plan->checks[1]->ruleName);
    }

    public function testParameterizedArrayTriggersDelegation()
    {
        $plan = $this->compile(['array:foo,bar', 'in:a,b,c']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[1]);
    }

    public function testDateWithLiteralTargetInlines()
    {
        $plan = $this->compile(['after:2025-01-01']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::DateAfter, $plan->checks[0]->type);
        $this->assertSame('2025-01-01', $plan->checks[0]->param['target']);
        $this->assertNull($plan->checks[0]->param['format']);
    }

    public function testDateWithFieldRefDelegates()
    {
        $plan = $this->compile(['after:start_date']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testDateWithHyphenatedFieldRefDelegates(): void
    {
        $plan = $this->compile(['after:start-date']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testDateWithDigitLeadingFieldRefDelegates(): void
    {
        $plan = $this->compile(['after:2fa-expiry']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testDateWithAmbiguousTimestampDelegates(): void
    {
        $plan = $this->compile(['after:20250102T120000Z']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testDateWithEscapedDotFieldRefDelegates(): void
    {
        $plan = $this->compile(['after:a\.b']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testDateWithSiblingFormatBaked()
    {
        $plan = $this->compile(['date_format:Y-m-d', 'after:2025-01-01']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[1]);
        $this->assertSame('Y-m-d', $plan->checks[1]->param['format']);
    }

    public function testScalarArrayFormDateFormatStillProvidesSiblingContext(): void
    {
        $plan = $this->compile([['date_format', 123], 'after:124']);

        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[1]);
        $this->assertSame('123', $plan->checks[1]->param['format']);
    }

    public function testNonScalarArrayFormDateFormatDelegatesTheWholeAttributeWithoutCasting(): void
    {
        $stringable = new class implements Stringable {
            public int $casts = 0;

            public function __toString(): string
            {
                ++$this->casts;

                return 'Y-m-d';
            }
        };

        $plan = $this->compile([['date_format', $stringable], 'after:2025-01-01']);

        $this->assertSame(0, $stringable->casts);
        $this->assertCount(2, $plan->checks);
        $this->assertContainsOnlyInstancesOf(DelegatedCheck::class, $plan->checks);
        $this->assertFalse($plan->checks[0]->parametersAreScalar);
        $this->assertTrue($plan->checks[1]->parametersAreScalar);
    }

    public function testNestedArrayParameterDelegatesTheWholeAttribute(): void
    {
        $plan = $this->compile([['date_format', []], 'string']);

        $this->assertCount(2, $plan->checks);
        $this->assertContainsOnlyInstancesOf(DelegatedCheck::class, $plan->checks);
    }

    public function testNullParameterDelegatesTheWholeAttribute(): void
    {
        $plan = $this->compile([['in', null], 'max:5']);

        $this->assertCount(2, $plan->checks);
        $this->assertContainsOnlyInstancesOf(DelegatedCheck::class, $plan->checks);
    }

    public function testResourceParameterDelegatesTheWholeAttribute(): void
    {
        $resource = fopen('php://memory', 'r');

        try {
            $plan = $this->compile([['in', $resource], 'max:5']);

            $this->assertCount(2, $plan->checks);
            $this->assertContainsOnlyInstancesOf(DelegatedCheck::class, $plan->checks);
        } finally {
            fclose($resource);
        }
    }

    public function testScalarArrayTupleKeepsTheOptimizedPath(): void
    {
        $plan = $this->compile([['in', 'a', 2], 'max:5']);

        $this->assertCount(2, $plan->checks);
        $this->assertContainsOnlyInstancesOf(InlineCheck::class, $plan->checks);
        $this->assertSame(['a', '2'], $plan->checks[0]->param);
    }

    public function testDateFormatStoresAllFormats()
    {
        $plan = $this->compile(['date_format:Y-m-d H:i:s,H:i:s']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::DateFormat, $plan->checks[0]->type);
        $this->assertSame(['Y-m-d H:i:s', 'H:i:s'], $plan->checks[0]->param);
    }

    public function testMixedInlineAndDelegated()
    {
        $existsRule = new Exists('users', 'email');

        $plan = $this->compile(['required', 'string', 'max:255', $existsRule]);

        $inlineCount = 0;
        $delegatedCount = 0;
        foreach ($plan->checks as $check) {
            if ($check instanceof InlineCheck) {
                ++$inlineCount;
            } else {
                ++$delegatedCount;
            }
        }

        $this->assertSame(2, $inlineCount);
        $this->assertSame(2, $delegatedCount);
    }

    public function testExistsRuleObjectStoresOriginalRule(): void
    {
        $existsRule = new Exists('users', 'email');
        $plan = $this->compile([$existsRule]);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame($existsRule, $plan->checks[0]->originalRule);
        $this->assertSame('Exists', $plan->checks[0]->ruleName);
    }

    public function testUniqueRuleObjectStoresOriginalRule(): void
    {
        $uniqueRule = new Unique('users', 'email');
        $plan = $this->compile([$uniqueRule]);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame($uniqueRule, $plan->checks[0]->originalRule);
        $this->assertSame('Unique', $plan->checks[0]->ruleName);
    }

    public function testClosureRuleProducesDelegatedCheck()
    {
        $closure = new ClosureValidationRule(function () {
            return true;
        });

        $plan = $this->compile([$closure]);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame($closure, $plan->checks[0]->originalRule);
    }

    public function testImplicitInvokableRuleProducesDelegatedCheck(): void
    {
        $implicitRule = new class implements RuleContract, ImplicitRule {
            public function passes(string $attribute, mixed $value): bool
            {
                return true;
            }

            public function message(): array|string
            {
                return '';
            }
        };

        $plan = $this->compile([$implicitRule]);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testImplicitStringRuleProducesDelegatedCheck(): void
    {
        $plan = $this->compile(['accepted']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame('Accepted', $plan->checks[0]->ruleName);
    }

    public function testAlphaAsciiVariant()
    {
        $plan = $this->compile(['alpha:ascii']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::AlphaAscii, $plan->checks[0]->type);
    }

    public function testArrayFormRuleParsedCorrectly()
    {
        $plan = $this->compile([['required_array_keys', 'name']]);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame('RequiredArrayKeys', $plan->checks[0]->ruleName);
        $this->assertSame(['name'], $plan->checks[0]->parameters);
    }

    public function testEmptyArrayRuleSkipped()
    {
        $plan = $this->compile([[]]);

        $this->assertCount(0, $plan->checks);
    }

    public function testCompileAllDelegatedProducesOnlyDelegatedChecks(): void
    {
        $plan = RuleCompiler::compileAllDelegated([
            'nullable',
            'bail',
            'sometimes',
            'required',
            'string',
            'max:255',
        ]);

        foreach ($plan->checks as $check) {
            $this->assertInstanceOf(DelegatedCheck::class, $check);
            $this->assertTrue($check->parametersAreScalar);
        }

        $this->assertTrue($plan->nullable);
        $this->assertTrue($plan->bail);
        $this->assertTrue($plan->sometimes);
        $this->assertCount(6, $plan->checks);
    }

    public function testMultipleOfLiteralInlines()
    {
        $plan = $this->compile(['multiple_of:5']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::MultipleOf, $plan->checks[0]->type);
    }

    public function testMultipleOfFieldRefDelegates()
    {
        $plan = $this->compile(['multiple_of:other_field']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testParameterizedArrayStillAllowsRuntimeDispatchedSizeInlining(): void
    {
        $plan = $this->compile(['array:name,email', 'max:5']);

        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[1]);
        $this->assertFalse($plan->checks[1]->param['numeric']);
    }

    public function testSizeThresholdsAreNormalizedAndClassifiedOnceAtCompilation(): void
    {
        $plan = $this->compile(['max: 5 ', 'min:1e3', 'size:9223372036854775808']);

        $this->assertSame(['raw' => '5', 'integer' => 5], $plan->checks[0]->param['threshold']);
        $this->assertSame(['raw' => '1e3', 'integer' => null], $plan->checks[1]->param['threshold']);
        $this->assertSame(
            ['raw' => '9223372036854775808', 'integer' => null],
            $plan->checks[2]->param['threshold'],
        );
    }

    public function testCompilerParsesEachRuleOnceForContextAndEmission(): void
    {
        $rule = new class implements Stringable {
            public int $casts = 0;

            public function __toString(): string
            {
                ++$this->casts;

                return 'max:5';
            }
        };

        $plan = $this->compile([$rule]);

        $this->assertSame(1, $rule->casts);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
    }

    public function testFormatCheckTypesInline()
    {
        $types = ['ip', 'ipv4', 'ipv6', 'ulid', 'json', 'ascii', 'hex_color', 'mac_address'];
        $expected = [
            CheckType::Ip, CheckType::Ipv4, CheckType::Ipv6, CheckType::Ulid,
            CheckType::Json, CheckType::Ascii, CheckType::HexColor, CheckType::MacAddress,
        ];

        foreach ($types as $i => $type) {
            $plan = $this->compile([$type]);
            $this->assertCount(1, $plan->checks, "Failed for rule: {$type}");
            $this->assertInstanceOf(InlineCheck::class, $plan->checks[0], "Failed for rule: {$type}");
            $this->assertSame($expected[$i], $plan->checks[0]->type, "Failed for rule: {$type}");
        }
    }

    public function testDigitsInlines()
    {
        $plan = $this->compile(['digits:5']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::Digits, $plan->checks[0]->type);
        $this->assertSame(5, $plan->checks[0]->param);
    }

    public function testMalformedDigitParametersDelegateInsteadOfBeingTruncated(): void
    {
        foreach ([
            'digits:2abc',
            'digits_between:2,3.0',
            'min_digits:2.9',
            'max_digits:abc',
        ] as $rule) {
            $plan = $this->compile([$rule]);

            $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0], $rule);
        }
    }

    public function testRegexInlines()
    {
        $plan = $this->compile(['regex:/^[a-z]+$/']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::Regex, $plan->checks[0]->type);
        $this->assertSame('/^[a-z]+$/', $plan->checks[0]->param);
    }

    public function testStartsWithInlines()
    {
        $plan = $this->compile(['starts_with:foo,bar']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::StartsWith, $plan->checks[0]->type);
        $this->assertSame(['foo', 'bar'], $plan->checks[0]->param);
    }

    public function testCrossFieldRulesDelegated()
    {
        $crossFieldRules = ['same:other', 'different:other', 'confirmed', 'gt:other', 'gte:other', 'lt:other', 'lte:other'];

        foreach ($crossFieldRules as $rule) {
            $plan = $this->compile([$rule]);
            $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0], "Expected DelegatedCheck for: {$rule}");
        }
    }

    public function testDateLiteralsRecognized()
    {
        foreach (['today', 'yesterday', 'tomorrow', 'now'] as $literal) {
            $plan = $this->compile(["after:{$literal}"]);
            $this->assertInstanceOf(InlineCheck::class, $plan->checks[0], "Expected InlineCheck for date literal: {$literal}");
        }
    }

    /**
     * Compile rules with the base validator's canonical numeric-rule set.
     */
    private function compile(array $rules): AttributePlan
    {
        return RuleCompiler::compile($rules, self::NUMERIC_RULES);
    }
}
