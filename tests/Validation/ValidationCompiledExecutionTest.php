<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation\ValidationCompiledExecutionTest;

use Closure;
use DateTimeImmutable;
use Hypervel\Contracts\Validation\ImplicitRule;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Http\UploadedFile;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\PresenceVerifierInterface;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use stdClass;
use Stringable;

class ValidationCompiledExecutionTest extends TestCase
{
    public function testBasicPassFail()
    {
        $v = $this->makeValidator(['name' => 'John'], ['name' => 'required|string|max:255']);
        $this->assertTrue($v->passes());

        $v = $this->makeValidator(['name' => ''], ['name' => 'required|string']);
        $this->assertFalse($v->passes());
    }

    public function testValidatedOutput()
    {
        $v = $this->makeValidator(
            ['name' => 'John', 'age' => 30, 'extra' => 'ignored'],
            ['name' => 'required|string', 'age' => 'required|integer'],
        );

        $this->assertSame(['name' => 'John', 'age' => 30], $v->validate());
    }

    public function testFailedOutput()
    {
        $v = $this->makeValidator(['name' => 123], ['name' => 'required|string']);
        $v->passes();

        $failed = $v->failed();
        $this->assertArrayHasKey('name', $failed);
        $this->assertArrayHasKey('String', $failed['name']);
    }

    public function testErrorMessagesWithReplacements()
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $translator->addLines([
            'validation.max.string' => ':attribute must not be greater than :max characters.',
        ], 'en');

        $v = new Validator($translator, ['name' => 'toolong'], ['name' => 'string|max:3']);
        $v->passes();

        $this->assertStringContainsString('3', $v->errors()->first('name'));
    }

    public function testBailStopsOnFirstFailure()
    {
        $v = $this->makeValidator(['name' => 123], ['name' => 'bail|string|max:255']);
        $v->passes();

        $this->assertCount(1, $v->errors()->get('name'));
    }

    public function testStopOnFirstFailure()
    {
        $v = $this->makeValidator(
            ['a' => 123, 'b' => 456],
            ['a' => 'string', 'b' => 'string'],
        );
        $v->stopOnFirstFailure();
        $v->passes();

        $this->assertTrue($v->errors()->has('a'));
        $this->assertFalse($v->errors()->has('b'));
    }

    public function testSometimesRespectsPresence()
    {
        $v = $this->makeValidator([], ['name' => 'sometimes|required|string']);
        $this->assertTrue($v->passes());

        $v = $this->makeValidator(['name' => ''], ['name' => 'sometimes|required|string']);
        $this->assertFalse($v->passes());
    }

    public function testNullableWithRequired()
    {
        $v = $this->makeValidator(['name' => null], ['name' => 'nullable|required']);
        $this->assertFalse($v->passes());

        $v = $this->makeValidator(['name' => null], ['name' => 'nullable|string']);
        $this->assertTrue($v->passes());
    }

    public function testRequiredOnEmptyString()
    {
        $v = $this->makeValidator(['name' => ''], ['name' => 'required|string']);
        $this->assertFalse($v->passes());
        $this->assertTrue($v->errors()->has('name'));
    }

    public function testCustomMessages()
    {
        $v = $this->makeValidator(
            ['name' => ''],
            ['name' => 'required'],
            ['name.required' => 'The name field is mandatory.'],
        );
        $v->passes();

        $this->assertSame('The name field is mandatory.', $v->errors()->first('name'));
    }

    public function testCustomAttributeNames()
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $translator->addLines(['validation.required' => ':attribute is required.'], 'en');

        $v = new Validator($translator, ['name' => ''], ['name' => 'required']);
        $v->setAttributeNames(['name' => 'Full Name']);
        $v->passes();

        $this->assertSame('Full Name is required.', $v->errors()->first('name'));
    }

    public function testClosureRule()
    {
        $v = $this->makeValidator(
            ['code' => 'invalid'],
            ['code' => [function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== 'valid') {
                    $fail('The code is invalid.');
                }
            }]],
        );

        $this->assertFalse($v->passes());
        $this->assertSame('The code is invalid.', $v->errors()->first('code'));
    }

    public function testRuleContractObject()
    {
        $rule = new class implements RuleContract {
            public function passes(string $attribute, mixed $value): bool
            {
                return $value === 'valid';
            }

            public function message(): array|string
            {
                return 'The :attribute is invalid.';
            }
        };

        $v = $this->makeValidator(['code' => 'invalid'], ['code' => [$rule]]);
        $this->assertFalse($v->passes());
    }

    public function testImplicitRuleRunsOnAbsentAttribute()
    {
        $rule = new class implements RuleContract, ImplicitRule {
            public function passes(string $attribute, mixed $value): bool
            {
                return $value !== null;
            }

            public function message(): array|string
            {
                return 'The :attribute is required.';
            }
        };

        $v = $this->makeValidator([], ['name' => [$rule]]);
        $this->assertFalse($v->passes());
    }

    public function testCustomExtension()
    {
        $v = $this->makeValidator(['code' => 'abc'], ['code' => 'custom_check']);
        $v->addExtension('custom_check', function ($attribute, $value) {
            return $value === 'valid';
        });

        $this->assertFalse($v->passes());
    }

    public function testDependentRulesResolveCorrectly()
    {
        $v = $this->makeValidator(
            ['password' => 'secret', 'password_confirmation' => 'secret'],
            ['password' => 'confirmed'],
        );
        $this->assertTrue($v->passes());

        $v = $this->makeValidator(
            ['password' => 'secret', 'password_confirmation' => 'different'],
            ['password' => 'confirmed'],
        );
        $this->assertFalse($v->passes());
    }

    public function testExcludeRulesProduceCorrectValidatedOutput()
    {
        $v = $this->makeValidator(
            ['type' => 'draft', 'title' => 'My Post', 'publish_date' => '2025-01-01'],
            [
                'type' => 'required|string',
                'title' => 'required|string',
                'publish_date' => 'exclude_if:type,draft|required|date',
            ],
        );

        $validated = $v->validate();
        $this->assertArrayNotHasKey('publish_date', $validated);
        $this->assertArrayHasKey('title', $validated);
    }

    public function testDependentRulesWithWildcardParameters()
    {
        $v = $this->makeValidator(
            ['items' => [
                ['start' => '2025-01-01', 'end' => '2025-12-31'],
                ['start' => '2025-06-01', 'end' => '2025-03-01'],
            ]],
            [
                'items.*.start' => 'required|date',
                'items.*.end' => 'required|date|after:items.*.start',
            ],
        );

        $this->assertFalse($v->passes());
        $this->assertTrue($v->errors()->has('items.1.end'));
        $this->assertFalse($v->errors()->has('items.0.end'));
    }

    public function testBooleanStrictDelegated()
    {
        $v = $this->makeValidator(['flag' => true], ['flag' => 'boolean:strict']);
        $this->assertTrue($v->passes());

        $v = $this->makeValidator(['flag' => 1], ['flag' => 'boolean:strict']);
        $this->assertFalse($v->passes());
    }

    public function testNumericStrictDelegated()
    {
        $v = $this->makeValidator(['age' => 30], ['age' => 'numeric:strict']);
        $this->assertTrue($v->passes());

        $v = $this->makeValidator(['age' => '30'], ['age' => 'numeric:strict']);
        $this->assertFalse($v->passes());
    }

    public function testInWithSiblingArrayUsesArrayDiffBranch()
    {
        $v = $this->makeValidator(
            ['tags' => ['php', 'js']],
            ['tags' => 'array', 'tags.*' => 'in:php,js,go'],
        );
        $this->assertTrue($v->passes());

        $v = $this->makeValidator(
            ['tags' => ['php', 'python']],
            ['tags' => 'array', 'tags.*' => 'in:php,js,go'],
        );
        $this->assertFalse($v->passes());
    }

    public function testWildcardValidationWithCorrectIndices()
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $translator->addLines(['validation.string' => ':attribute must be a string.'], 'en');

        $v = new Validator(
            $translator,
            ['items' => [['name' => 'valid'], ['name' => 123]]],
            ['items.*.name' => 'required|string'],
        );

        $this->assertFalse($v->passes());
        $this->assertTrue($v->errors()->has('items.1.name'));
        $this->assertFalse($v->errors()->has('items.0.name'));
    }

    public function testSubclassWithOverriddenValidateStringIsNotBypassed()
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $v = new AlwaysFailStringValidator($translator, ['name' => 'hello'], ['name' => 'string']);

        $this->assertFalse($v->passes());
    }

    public function testPreOptimizationGuardSkipsWithCustomExtensions()
    {
        $v = $this->makeValidator(
            ['type' => 'section', 'details' => 'test'],
            ['type' => 'required|string', 'details' => 'exclude_unless:type,chapter|required|string'],
        );
        $v->addExtension('my_ext', function () {
            return true;
        });

        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('details', $v->validated());
    }

    public function testCustomPresenceVerifierDisablesBatching()
    {
        $customVerifier = new class implements PresenceVerifierInterface {
            public function getCount(string $collection, string $column, mixed $value, int|string|null $excludeId = null, ?string $idColumn = null, array $extra = []): int
            {
                return $value === 'exists@example.com' ? 1 : 0;
            }

            public function getMultiCount(string $collection, string $column, array $values, array $extra = []): int
            {
                return 0;
            }
        };

        $v = $this->makeValidator(
            ['items' => [['email' => 'exists@example.com']]],
            ['items.*.email' => 'required|exists:users,email'],
        );
        $v->setPresenceVerifier($customVerifier);

        $this->assertTrue($v->passes());
    }

    public function testInvalidUploadedFileProducesUploadedError()
    {
        $file = new UploadedFile(
            path: '',
            originalName: 'test.jpg',
            mimeType: 'image/jpeg',
            error: UPLOAD_ERR_INI_SIZE,
            test: true,
        );

        $v = $this->makeValidator(['file' => $file], ['file' => 'required|image']);
        $v->passes();

        $this->assertTrue($v->errors()->has('file'));
    }

    public function testExcludeAttributesResetAcrossValidatorReuse()
    {
        $v = $this->makeValidator(
            ['type' => 'draft', 'details' => 'some details'],
            ['type' => 'required|string', 'details' => 'exclude_if:type,draft|required|string'],
        );

        $v->passes();
        $this->assertArrayNotHasKey('details', $v->validated());

        // Reuse the same validator with different data
        $v->setData(['type' => 'published', 'details' => 'some details']);
        $v->passes();

        // details should now be INCLUDED (type is no longer draft)
        $this->assertArrayHasKey('details', $v->validated());
    }

    public function testPresenceVerifierRestoredAfterException()
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $v = new Validator($translator, ['name' => 'test'], ['name' => 'required|string']);

        $originalVerifier = new class implements PresenceVerifierInterface {
            public function getCount(string $collection, string $column, mixed $value, int|string|null $excludeId = null, ?string $idColumn = null, array $extra = []): int
            {
                return 0;
            }

            public function getMultiCount(string $collection, string $column, array $values, array $extra = []): int
            {
                return 0;
            }
        };

        $v->setPresenceVerifier($originalVerifier);

        // First passes() should work normally
        $this->assertTrue($v->passes());

        // The presence verifier should still be the original after passes()
        $this->assertSame($originalVerifier, $v->getPresenceVerifier());
    }

    public function testRulePlanCacheCannotCollideAcrossValidatorsWithRegexPipes(): void
    {
        $first = $this->makeValidator([], [
            'value' => ['regex:/^foo|bar$/', 'string'],
        ]);
        $second = $this->makeValidator([], [
            'value' => ['regex:/^foo', 'bar$/', 'string'],
        ]);

        $this->assertTrue($first->passes());
        $this->assertTrue($second->passes());

        $compiledPlans = new ReflectionProperty(Validator::class, 'compiledPlans');
        $firstPlan = $compiledPlans->getValue($first)['value'];
        $secondPlan = $compiledPlans->getValue($second)['value'];

        $this->assertNotSame($firstPlan, $secondPlan);
        $this->assertNotCount(count($firstPlan->checks), $secondPlan->checks);
    }

    #[DataProvider('guardedRuleCases')]
    public function testGuardedRuleDomainsMatchInCompiledAndDelegatedExecution(
        string $rule,
        mixed $value,
        bool $expected,
    ): void {
        foreach ([Validator::class, DelegatedValidationValidator::class] as $validatorClass) {
            $validator = $this->makeValidator(
                ['value' => $value],
                ['value' => $rule],
                validatorClass: $validatorClass,
            );

            $this->assertSame($expected, $validator->passes(), $validatorClass . ' failed for ' . $rule);
        }
    }

    public static function guardedRuleCases(): iterable
    {
        yield 'ascii accepts string' => ['ascii', 'plain text', true];
        yield 'ascii rejects integer' => ['ascii', 123, false];
        yield 'lowercase accepts string' => ['lowercase', 'lowercase', true];
        yield 'lowercase rejects Stringable' => ['lowercase', new ValidationStringableValue('lowercase'), false];
        yield 'uppercase accepts string' => ['uppercase', 'UPPERCASE', true];
        yield 'uppercase rejects boolean' => ['uppercase', true, false];
        yield 'hex color accepts string' => ['hex_color', '#aabbcc', true];
        yield 'hex color rejects array' => ['hex_color', ['#aabbcc'], false];
        yield 'digits accepts numeric string' => ['digits:3', '123', true];
        yield 'digits accepts integer' => ['digits:3', 123, true];
        yield 'digits rejects float with decimal point' => ['digits:3', 12.3, false];
        yield 'digits rejects null' => ['digits:3', null, false];
        yield 'digits between rejects array' => ['digits_between:1,3', ['123'], false];
        yield 'minimum digits rejects object' => ['min_digits:2', new stdClass, false];
        yield 'maximum digits rejects Stringable' => ['max_digits:2', new ValidationStringableValue('12'), false];
        yield 'starts with accepts integer' => ['starts_with:12', 123, true];
        yield 'starts with accepts float' => ['starts_with:12', 12.3, true];
        yield 'starts with rejects array' => ['starts_with:12', [123], false];
        yield 'ends with rejects boolean' => ['ends_with:1', true, false];
        yield 'does not start with rejects null' => ['doesnt_start_with:12', null, false];
        yield 'does not end with rejects object' => ['doesnt_end_with:3', new stdClass, false];
        yield 'in accepts Stringable' => ['in:active', new ValidationStringableValue('active'), true];
        yield 'in accepts boolean scalar' => ['in:1', true, true];
        yield 'in rejects object' => ['in:active', new stdClass, false];
        yield 'not in accepts object' => ['not_in:active', new stdClass, true];
        yield 'in rejects backed enum value' => ['in:active', MembershipStatus::Active, false];
        yield 'not in accepts backed enum value' => ['not_in:active', MembershipStatus::Active, true];
    }

    public function testMembershipRulesMatchInCompiledAndDelegatedExecution(): void
    {
        foreach ([Validator::class, DelegatedValidationValidator::class] as $validatorClass) {
            $this->assertFalse($this->makeValidator(
                ['value' => '0e456'],
                ['value' => 'in:0e123'],
                validatorClass: $validatorClass,
            )->passes());
            $this->assertTrue($this->makeValidator(
                ['value' => '0e456'],
                ['value' => 'not_in:0e123'],
                validatorClass: $validatorClass,
            )->passes());
            $this->assertTrue($this->makeValidator(
                ['value' => 1],
                ['value' => [['in', 1, 2]]],
                validatorClass: $validatorClass,
            )->passes());
            $this->assertFalse($this->makeValidator(
                ['value' => 1],
                ['value' => [['not_in', 1, 2]]],
                validatorClass: $validatorClass,
            )->passes());
            $this->assertTrue($this->makeValidator(
                ['value' => ['blocked']],
                ['value' => 'not_in:blocked'],
                validatorClass: $validatorClass,
            )->passes());

            $in = $this->makeValidator(
                ['value' => 'other'],
                ['value' => [Rule::in([MembershipStatus::Active])]],
                ['value.in' => 'The selected :attribute is invalid. Allowed: :values'],
                $validatorClass,
            );
            $this->assertFalse($in->passes());
            $this->assertSame('The selected value is invalid. Allowed: active', $in->errors()->first('value'));

            $notIn = $this->makeValidator(
                ['value' => 'active'],
                ['value' => [Rule::notIn([MembershipStatus::Active])]],
                ['value.not_in' => 'The selected :attribute is invalid. Not allowed: :values'],
                $validatorClass,
            );
            $this->assertFalse($notIn->passes());
            $this->assertSame('The selected value is invalid. Not allowed: active', $notIn->errors()->first('value'));

            $rawIn = $this->makeValidator(
                ['value' => 3],
                ['value' => [['in', 1, 2]]],
                ['value.in' => 'Allowed: :values'],
                $validatorClass,
            );
            $this->assertFalse($rawIn->passes());
            $this->assertSame('Allowed: 1, 2', $rawIn->errors()->first('value'));

            $rawNotIn = $this->makeValidator(
                ['value' => 1],
                ['value' => [['not_in', 1, 2]]],
                ['value.not_in' => 'Not allowed: :values'],
                $validatorClass,
            );
            $this->assertFalse($rawNotIn->passes());
            $this->assertSame('Not allowed: 1, 2', $rawNotIn->errors()->first('value'));
        }
    }

    #[DataProvider('dateComparisonCases')]
    public function testDateComparisonPolicyMatchesInCompiledAndDelegatedExecution(
        array $data,
        array $rules,
        bool $expected,
    ): void {
        foreach ([Validator::class, DelegatedValidationValidator::class] as $validatorClass) {
            $validator = $this->makeValidator($data, $rules, validatorClass: $validatorClass);

            $this->assertSame($expected, $validator->passes(), $validatorClass);
        }
    }

    public static function dateComparisonCases(): iterable
    {
        yield 'before literal' => [
            ['value' => '2025-01-01'],
            ['value' => 'before:2025-01-02'],
            true,
        ];
        yield 'before or equal literal' => [
            ['value' => '2025-01-01'],
            ['value' => 'before_or_equal:2025-01-01'],
            true,
        ];
        yield 'after literal' => [
            ['value' => '2025-01-02'],
            ['value' => 'after:2025-01-01'],
            true,
        ];
        yield 'after or equal literal' => [
            ['value' => '2025-01-01'],
            ['value' => 'after_or_equal:2025-01-01'],
            true,
        ];
        yield 'date equals literal' => [
            ['value' => '2025-01-01'],
            ['value' => 'date_equals:2025-01-01'],
            true,
        ];
        yield 'field reference' => [
            ['value' => '2025-01-02', 'target' => '2025-01-01'],
            ['value' => 'after:target'],
            true,
        ];
        yield 'hyphenated field reference passes in the correct direction' => [
            ['value' => '2025-01-02', 'target-date' => '2025-01-01'],
            ['value' => 'after:target-date'],
            true,
        ];
        yield 'hyphenated field reference fails in the wrong direction' => [
            ['value' => '2025-01-01', 'target-date' => '2025-01-02'],
            ['value' => 'after:target-date'],
            false,
        ];
        yield 'formatted hyphenated field reference passes in the correct direction' => [
            ['value' => '02/01/2025', 'target-date' => '01/01/2025'],
            ['value' => 'date_format:d/m/Y|after:target-date', 'target-date' => 'date_format:d/m/Y'],
            true,
        ];
        yield 'formatted hyphenated field reference fails in the wrong direction' => [
            ['value' => '01/01/2025', 'target-date' => '02/01/2025'],
            ['value' => 'date_format:d/m/Y|after:target-date', 'target-date' => 'date_format:d/m/Y'],
            false,
        ];
        yield 'digit-leading field reference passes in the correct direction' => [
            ['value' => '2025-01-02', '2fa-expiry' => '2025-01-01'],
            ['value' => 'after:2fa-expiry'],
            true,
        ];
        yield 'digit-leading field reference fails in the wrong direction' => [
            ['value' => '2025-01-01', '2fa-expiry' => '2025-01-02'],
            ['value' => 'after:2fa-expiry'],
            false,
        ];
        yield 'nested hyphenated field reference passes in the correct direction' => [
            ['items' => [['start-date' => '2025-01-01', 'end-date' => '2025-01-02']]],
            ['items.*.end-date' => 'after:items.*.start-date'],
            true,
        ];
        yield 'nested hyphenated field reference fails in the wrong direction' => [
            ['items' => [['start-date' => '2025-01-02', 'end-date' => '2025-01-01']]],
            ['items.*.end-date' => 'after:items.*.start-date'],
            false,
        ];
        yield 'absent field passes' => [
            ['value' => '2025-01-02'],
            ['value' => 'after:target'],
            true,
        ];
        yield 'null field passes' => [
            ['value' => '2025-01-02', 'target' => null],
            ['value' => 'after:target'],
            true,
        ];
        yield 'empty field fails' => [
            ['value' => '2025-01-02', 'target' => ''],
            ['value' => 'after:target'],
            false,
        ];
        yield 'invalid field fails' => [
            ['value' => '2025-01-02', 'target' => '!!!'],
            ['value' => 'after:target'],
            false,
        ];
        yield 'array field fails' => [
            ['value' => '2025-01-02', 'target' => []],
            ['value' => 'after:target'],
            false,
        ];
        yield 'formatted array field fails' => [
            ['value' => '2025-01-02', 'target' => []],
            ['value' => 'date_format:Y-m-d|after:target', 'target' => 'date_format:Y-m-d'],
            false,
        ];
        yield 'invalid literal fails' => [
            ['value' => '2025-01-02'],
            ['value' => 'after:!!!'],
            false,
        ];
        yield 'invalid current value fails before absent-field handling' => [
            ['value' => []],
            ['value' => 'after:target'],
            false,
        ];
        yield 'escaped dot field reference' => [
            ['value' => '2025-01-02', 'target.date' => '2025-01-01'],
            ['value' => 'after:target\.date'],
            true,
        ];
        yield 'date equals wildcard field reference' => [
            ['items' => [['start' => '2025-01-01', 'end' => '2025-01-01']]],
            ['items.*.end' => 'date_equals:items.*.start'],
            true,
        ];
        yield 'DateTime value' => [
            ['value' => new DateTimeImmutable('2025-01-01')],
            ['value' => 'before:2025-01-02'],
            true,
        ];
        yield 'epoch field equality' => [
            ['value' => 0, 'target' => 0],
            ['value' => 'date_equals:target'],
            true,
        ];
        yield 'formatted integer values' => [
            ['value' => 20250101],
            ['value' => 'date_format:Ymd|before:20250102'],
            true,
        ];
        yield 'Stringable array-form date format' => [
            ['value' => 20250101],
            ['value' => [['date_format', new ValidationStringableValue('Ymd')], ['before', '20250102']]],
            true,
        ];
        yield 'integer array-form date format' => [
            ['value' => 2025],
            ['value' => [['date_format', 2025], ['date_equals', 2025]]],
            true,
        ];
        yield 'malformed date format on absent attribute remains optional' => [
            [],
            ['value' => [['date_format', new stdClass]]],
            true,
        ];
    }

    public function testDateFieldFailureMessagesDecodeResolvedReferences(): void
    {
        foreach ([Validator::class, DelegatedValidationValidator::class] as $validatorClass) {
            $escapedDot = $this->makeValidator(
                ['value' => '2025-01-01', 'target.date' => '2025-01-02'],
                ['value' => 'after:target\.date'],
                ['value.after' => 'The :attribute must be after :date.'],
                $validatorClass,
            );

            $this->assertFalse($escapedDot->passes());
            $this->assertSame('The value must be after target.date.', $escapedDot->errors()->first('value'));

            $wildcard = $this->makeValidator(
                ['items' => [['start' => '2025-01-01', 'end' => '2025-01-02']]],
                ['items.*.end' => 'date_equals:items.*.start'],
                ['items.*.end.date_equals' => 'The :attribute must equal :date.'],
                $validatorClass,
            );

            $this->assertFalse($wildcard->passes());
            $this->assertSame(
                'The items.0.end must equal items.0.start.',
                $wildcard->errors()->first('items.0.end'),
            );
        }
    }

    public function testIntegerDateFormatRendersInFailureMessage(): void
    {
        foreach ([Validator::class, DelegatedValidationValidator::class] as $validatorClass) {
            $validator = $this->makeValidator(
                ['value' => 'invalid'],
                ['value' => [['date_format', 2025]]],
                ['value.date_format' => 'The :attribute must match :format.'],
                $validatorClass,
            );

            $this->assertFalse($validator->passes());
            $this->assertSame('The value must match 2025.', $validator->errors()->first('value'));
        }
    }

    #[DataProvider('malformedDigitParameterCases')]
    public function testMalformedDigitParametersDelegateWithoutTruncation(string $rule, bool $expected): void
    {
        foreach ([Validator::class, DelegatedValidationValidator::class] as $validatorClass) {
            $validator = $this->makeValidator(
                ['value' => '12'],
                ['value' => $rule],
                validatorClass: $validatorClass,
            );

            $this->assertSame($expected, $validator->passes(), $validatorClass . ' failed for ' . $rule);
        }
    }

    public static function malformedDigitParameterCases(): iterable
    {
        yield 'suffix' => ['digits:2abc', false];
        yield 'fraction' => ['digits:2.9', false];
        yield 'decimal integer' => ['digits:2.0', true];
        yield 'non-numeric' => ['digits:abc', false];
    }

    public function testEmailRejectsLineBreaksInCompiledAndDelegatedExecution(): void
    {
        foreach ([Validator::class, DelegatedValidationValidator::class] as $validatorClass) {
            foreach (['email', 'email:rfc'] as $rule) {
                foreach (["user@example.com\r", "user@example.com\n"] as $value) {
                    $validator = $this->makeValidator(
                        ['value' => $value],
                        ['value' => $rule],
                        validatorClass: $validatorClass,
                    );

                    $this->assertFalse($validator->passes(), $validatorClass . ' failed for ' . $rule);
                }
            }
        }
    }

    public function testShouldStopValidatingStillStopsAfterImplicitFailure()
    {
        $v = $this->makeValidator(['name' => ''], ['name' => 'required|string']);
        $v->passes();

        $errors = $v->errors()->get('name');
        $this->assertCount(1, $errors);
        $this->assertTrue($v->errors()->has('name'));
    }

    public function testSometimesWithAbsentAttributeSkipsDelegatedRulesInCompiledPath()
    {
        $v = $this->makeValidator(
            ['items' => [['name' => 'valid']]],
            [
                'items.*.name' => 'sometimes|required|string',
                'items.*.code' => 'sometimes|required|string',
            ],
        );

        $this->assertTrue($v->passes());

        $validated = $v->validated();
        $this->assertArrayHasKey('name', $validated['items'][0]);
        $this->assertArrayNotHasKey('code', $validated['items'][0]);
    }

    public function testImplicitAttributeMapInvalidatedAfterSometimes()
    {
        $v = $this->makeValidator(
            [
                'items' => [['name' => 'a']],
                'extra' => [['code' => 'x']],
            ],
            ['items.*.name' => 'required|string'],
        );

        $v->sometimes('extra.*.code', 'required|string', fn () => true);

        $this->assertTrue($v->passes());

        $validated = $v->validated();
        $this->assertSame('a', $validated['items'][0]['name']);
        $this->assertSame('x', $validated['extra'][0]['code']);
    }

    /**
     * Create a validator for the requested execution path.
     *
     * @param class-string<Validator> $validatorClass
     */
    private function makeValidator(
        array $data,
        array $rules,
        array $messages = [],
        string $validatorClass = Validator::class,
    ): Validator {
        return new $validatorClass(
            new Translator(new ArrayLoader, 'en'),
            $data,
            $rules,
            $messages,
        );
    }
}

/**
 * Test validator subclass that overrides validateString to always fail.
 *
 * Used to verify that subclass validate*() overrides are not bypassed
 * by inlining — subclasses must use compileAllDelegated().
 */
class AlwaysFailStringValidator extends Validator
{
    public function validateString(string $attribute, mixed $value): bool
    {
        return false;
    }
}

class DelegatedValidationValidator extends Validator
{
}

class ValidationStringableValue implements Stringable
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

enum MembershipStatus: string
{
    case Active = 'active';
}
