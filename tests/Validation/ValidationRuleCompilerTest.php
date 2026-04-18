<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Contracts\Validation\ImplicitRule;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\ClosureValidationRule;
use Hypervel\Validation\DelegatedCheck;
use Hypervel\Validation\Enums\CheckType;
use Hypervel\Validation\Enums\SizeMode;
use Hypervel\Validation\InlineCheck;
use Hypervel\Validation\RuleCompiler;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;

class ValidationRuleCompilerTest extends TestCase
{
    public function testRequiredSetsFlagAndProducesDelegatedCheck()
    {
        $plan = RuleCompiler::compile(['required']);

        $this->assertTrue($plan->required);
        $this->assertTrue($plan->hasImplicitRule);
        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame('Required', $plan->checks[0]->ruleName);
    }

    public function testNullableSetsFlag()
    {
        $plan = RuleCompiler::compile(['nullable']);

        $this->assertTrue($plan->nullable);
        $this->assertCount(0, $plan->checks);
    }

    public function testBailSetsFlag()
    {
        $plan = RuleCompiler::compile(['bail']);

        $this->assertTrue($plan->bail);
        $this->assertCount(0, $plan->checks);
    }

    public function testSometimesSetsFlag()
    {
        $plan = RuleCompiler::compile(['sometimes']);

        $this->assertTrue($plan->sometimes);
        $this->assertCount(0, $plan->checks);
    }

    public function testEmptyRuleStringProducesNoCheck()
    {
        $plan = RuleCompiler::compile(['']);

        $this->assertCount(0, $plan->checks);
    }

    public function testStringInlinesCorrectly()
    {
        $plan = RuleCompiler::compile(['string']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::TypeString, $plan->checks[0]->type);
    }

    public function testNumericBareInlines()
    {
        $plan = RuleCompiler::compile(['numeric']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::TypeNumeric, $plan->checks[0]->type);
    }

    public function testNumericStrictDelegates()
    {
        $plan = RuleCompiler::compile(['numeric:strict']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame('Numeric', $plan->checks[0]->ruleName);
    }

    public function testBooleanBareInlines()
    {
        $plan = RuleCompiler::compile(['boolean']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::TypeBoolean, $plan->checks[0]->type);
    }

    public function testBooleanStrictDelegates()
    {
        $plan = RuleCompiler::compile(['boolean:strict']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testIntegerBareInlines()
    {
        $plan = RuleCompiler::compile(['integer']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::TypeInteger, $plan->checks[0]->type);
    }

    public function testIntegerStrictInlines()
    {
        $plan = RuleCompiler::compile(['integer:strict']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::TypeIntegerStrict, $plan->checks[0]->type);
    }

    public function testUuidBareInlines()
    {
        $plan = RuleCompiler::compile(['uuid']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::Uuid, $plan->checks[0]->type);
    }

    public function testUuidWithVersionDelegates()
    {
        $plan = RuleCompiler::compile(['uuid:4']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testEmailBareInlines()
    {
        $plan = RuleCompiler::compile(['email']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::Email, $plan->checks[0]->type);
    }

    public function testEmailWithParamsDelegates()
    {
        $plan = RuleCompiler::compile(['email:rfc,dns']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testUrlBareInlines()
    {
        $plan = RuleCompiler::compile(['url']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
    }

    public function testUrlWithParamsDelegates()
    {
        $plan = RuleCompiler::compile(['url:http,https']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testArrayBareInlines()
    {
        $plan = RuleCompiler::compile(['array']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::TypeArray, $plan->checks[0]->type);
    }

    public function testArrayWithKeysDelegates()
    {
        $plan = RuleCompiler::compile(['array:name,email']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testSizeRulesWithResolvedMode()
    {
        $plan = RuleCompiler::compile(['string', 'max:255']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[1]);
        $this->assertSame(CheckType::SizeMax, $plan->checks[1]->type);
        $this->assertSame('255', $plan->checks[1]->param['n']);
        $this->assertSame(SizeMode::String, $plan->checks[1]->param['mode']);
    }

    public function testSizeRulesWithoutTypeFlagDelegate()
    {
        $plan = RuleCompiler::compile(['max:255']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame('Max', $plan->checks[0]->ruleName);
    }

    public function testSizeRulesWithConflictingTypeFlagsDelegate()
    {
        $plan = RuleCompiler::compile(['numeric', 'string', 'max:10']);

        $this->assertCount(3, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[2]);
        $this->assertSame('Max', $plan->checks[2]->ruleName);
    }

    public function testBetweenWithResolvedMode()
    {
        $plan = RuleCompiler::compile(['numeric', 'between:1,100']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[1]);
        $this->assertSame(CheckType::SizeBetween, $plan->checks[1]->type);
        $this->assertSame('1', $plan->checks[1]->param['min']);
        $this->assertSame('100', $plan->checks[1]->param['max']);
        $this->assertSame(SizeMode::Numeric, $plan->checks[1]->param['mode']);
    }

    public function testInWithoutSiblingArrayInlines()
    {
        $plan = RuleCompiler::compile(['in:a,b,c']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::In, $plan->checks[0]->type);
    }

    public function testInWithSiblingArrayDelegates()
    {
        $plan = RuleCompiler::compile(['array', 'in:a,b,c']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[1]);
        $this->assertSame('In', $plan->checks[1]->ruleName);
    }

    public function testNotInWithSiblingArrayDelegates()
    {
        $plan = RuleCompiler::compile(['array', 'not_in:a,b,c']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[1]);
    }

    public function testArrayFormSiblingArrayTriggersDelegation()
    {
        // Array-form ['array'] must be detected as a sibling array rule,
        // causing 'in' to delegate (uses array_diff branch in validateIn).
        $plan = RuleCompiler::compile([['array'], 'in:a,b,c']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[1]);
        $this->assertSame('In', $plan->checks[1]->ruleName);
    }

    public function testParameterizedArrayTriggersDelegation()
    {
        $plan = RuleCompiler::compile(['array:foo,bar', 'in:a,b,c']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[1]);
    }

    public function testDateWithLiteralTargetInlines()
    {
        $plan = RuleCompiler::compile(['after:2025-01-01']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::DateAfter, $plan->checks[0]->type);
        $this->assertSame('2025-01-01', $plan->checks[0]->param['target']);
        $this->assertNull($plan->checks[0]->param['format']);
    }

    public function testDateWithFieldRefDelegates()
    {
        $plan = RuleCompiler::compile(['after:start_date']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testDateWithSiblingFormatBaked()
    {
        $plan = RuleCompiler::compile(['date_format:Y-m-d', 'after:2025-01-01']);

        $this->assertCount(2, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[1]);
        $this->assertSame('Y-m-d', $plan->checks[1]->param['format']);
    }

    public function testDateFormatStoresAllFormats()
    {
        $plan = RuleCompiler::compile(['date_format:Y-m-d H:i:s,H:i:s']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::DateFormat, $plan->checks[0]->type);
        $this->assertSame(['Y-m-d H:i:s', 'H:i:s'], $plan->checks[0]->param);
    }

    public function testMixedInlineAndDelegated()
    {
        $existsRule = new Exists('users', 'email');

        $plan = RuleCompiler::compile(['required', 'string', 'max:255', $existsRule]);

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

    public function testExistsRuleObjectStoresRuleAndOriginal()
    {
        $existsRule = new Exists('users', 'email');
        $plan = RuleCompiler::compile([$existsRule]);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame($existsRule, $plan->checks[0]->ruleObject);
        $this->assertSame($existsRule, $plan->checks[0]->originalRule);
        $this->assertSame('Exists', $plan->checks[0]->ruleName);
    }

    public function testUniqueRuleObjectStoresRuleAndOriginal()
    {
        $uniqueRule = new Unique('users', 'email');
        $plan = RuleCompiler::compile([$uniqueRule]);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame($uniqueRule, $plan->checks[0]->ruleObject);
        $this->assertSame('Unique', $plan->checks[0]->ruleName);
    }

    public function testClosureRuleProducesDelegatedCheck()
    {
        $closure = new ClosureValidationRule(function () {
            return true;
        });

        $plan = RuleCompiler::compile([$closure]);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame($closure, $plan->checks[0]->ruleObject);
    }

    public function testImplicitInvokableRuleSetsHasImplicitRule()
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

        $plan = RuleCompiler::compile([$implicitRule]);

        $this->assertTrue($plan->hasImplicitRule);
        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testImplicitStringRulesSetsHasImplicitRule()
    {
        $plan = RuleCompiler::compile(['accepted']);

        $this->assertTrue($plan->hasImplicitRule);
    }

    public function testAlphaAsciiVariant()
    {
        $plan = RuleCompiler::compile(['alpha:ascii']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::AlphaAscii, $plan->checks[0]->type);
    }

    public function testArrayFormRuleParsedCorrectly()
    {
        $plan = RuleCompiler::compile([['required_array_keys', 'name']]);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        $this->assertSame('RequiredArrayKeys', $plan->checks[0]->ruleName);
        $this->assertSame(['name'], $plan->checks[0]->parameters);
    }

    public function testEmptyArrayRuleSkipped()
    {
        $plan = RuleCompiler::compile([[]]);

        $this->assertCount(0, $plan->checks);
    }

    public function testCompileAllDelegatedProducesNoDelegatedChecks()
    {
        $plan = RuleCompiler::compileAllDelegated(['required', 'string', 'max:255']);

        foreach ($plan->checks as $check) {
            $this->assertInstanceOf(DelegatedCheck::class, $check);
        }

        $this->assertTrue($plan->required);
        $this->assertTrue($plan->hasImplicitRule);
    }

    public function testMultipleOfLiteralInlines()
    {
        $plan = RuleCompiler::compile(['multiple_of:5']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::MultipleOf, $plan->checks[0]->type);
    }

    public function testMultipleOfFieldRefDelegates()
    {
        $plan = RuleCompiler::compile(['multiple_of:other_field']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
    }

    public function testSizeModeResolvesFromParameterizedArray()
    {
        $plan = RuleCompiler::compile(['array:name,email', 'max:5']);

        $this->assertSame(SizeMode::Array, $plan->sizeMode);
        $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0]);
        // max:5 delegates because array was parameterized but still sets Array mode
        // However, in:* would delegate because hasSiblingArrayRule is true
    }

    public function testFormatCheckTypesInline()
    {
        $types = ['ip', 'ipv4', 'ipv6', 'ulid', 'json', 'ascii', 'hex_color', 'mac_address'];
        $expected = [
            CheckType::Ip, CheckType::Ipv4, CheckType::Ipv6, CheckType::Ulid,
            CheckType::Json, CheckType::Ascii, CheckType::HexColor, CheckType::MacAddress,
        ];

        foreach ($types as $i => $type) {
            $plan = RuleCompiler::compile([$type]);
            $this->assertCount(1, $plan->checks, "Failed for rule: {$type}");
            $this->assertInstanceOf(InlineCheck::class, $plan->checks[0], "Failed for rule: {$type}");
            $this->assertSame($expected[$i], $plan->checks[0]->type, "Failed for rule: {$type}");
        }
    }

    public function testDigitsInlines()
    {
        $plan = RuleCompiler::compile(['digits:5']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::Digits, $plan->checks[0]->type);
        $this->assertSame(5, $plan->checks[0]->param);
    }

    public function testRegexInlines()
    {
        $plan = RuleCompiler::compile(['regex:/^[a-z]+$/']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::Regex, $plan->checks[0]->type);
        $this->assertSame('/^[a-z]+$/', $plan->checks[0]->param);
    }

    public function testStartsWithInlines()
    {
        $plan = RuleCompiler::compile(['starts_with:foo,bar']);

        $this->assertCount(1, $plan->checks);
        $this->assertInstanceOf(InlineCheck::class, $plan->checks[0]);
        $this->assertSame(CheckType::StartsWith, $plan->checks[0]->type);
        $this->assertSame(['foo', 'bar'], $plan->checks[0]->param);
    }

    public function testCrossFieldRulesDelegated()
    {
        $crossFieldRules = ['same:other', 'different:other', 'confirmed', 'gt:other', 'gte:other', 'lt:other', 'lte:other'];

        foreach ($crossFieldRules as $rule) {
            $plan = RuleCompiler::compile([$rule]);
            $this->assertInstanceOf(DelegatedCheck::class, $plan->checks[0], "Expected DelegatedCheck for: {$rule}");
        }
    }

    public function testDateLiteralsRecognized()
    {
        foreach (['today', 'yesterday', 'tomorrow', 'now'] as $literal) {
            $plan = RuleCompiler::compile(["after:{$literal}"]);
            $this->assertInstanceOf(InlineCheck::class, $plan->checks[0], "Expected InlineCheck for date literal: {$literal}");
        }
    }
}
