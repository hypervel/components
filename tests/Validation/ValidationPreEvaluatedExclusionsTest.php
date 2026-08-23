<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Closure;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Contracts\Validation\ValidatorAwareRule;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Validator;
use InvalidArgumentException;
use Stringable;

class ValidationPreEvaluatedExclusionsTest extends TestCase
{
    public function testExcludeUnlessRemovesAttributeWhenConditionNotMet(): void
    {
        $v = $this->makeValidator(
            ['type' => 'section', 'details' => 'some details'],
            ['type' => 'required|string', 'details' => 'exclude_unless:type,chapter|required|string'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('details', $v->validated());
    }

    public function testExcludeUnlessKeepsAttributeWhenConditionMet(): void
    {
        $v = $this->makeValidator(
            ['type' => 'chapter', 'details' => 'some details'],
            ['type' => 'required|string', 'details' => 'exclude_unless:type,chapter|required|string'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayHasKey('details', $v->validated());
    }

    public function testExcludeIfRemovesAttributeWhenConditionMet(): void
    {
        $v = $this->makeValidator(
            ['type' => 'draft', 'publish_date' => '2025-01-01'],
            ['type' => 'required|string', 'publish_date' => 'exclude_if:type,draft|required|date'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('publish_date', $v->validated());
    }

    public function testExcludeIfKeepsAttributeWhenConditionNotMet(): void
    {
        $v = $this->makeValidator(
            ['type' => 'published', 'publish_date' => '2025-01-01'],
            ['type' => 'required|string', 'publish_date' => 'exclude_if:type,draft|required|date'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayHasKey('publish_date', $v->validated());
    }

    public function testActiveExclusionsResetBetweenPasses(): void
    {
        $validator = $this->makeValidator(
            ['flag' => 'yes', 'first' => 'first value', 'second' => 'second value'],
            [
                'flag' => 'required|string',
                'first' => 'exclude_if:flag,yes|required|string',
                'second' => 'exclude_if:flag,no|required|string',
            ],
        );

        $this->assertTrue($validator->passes());
        $this->assertSame(
            ['flag' => 'yes', 'second' => 'second value'],
            $validator->getData(),
        );

        $validator->setData([
            'flag' => 'no',
            'first' => 'first value',
            'second' => 'second value',
        ]);

        $this->assertTrue($validator->passes());
        $this->assertSame(
            ['flag' => 'no', 'first' => 'first value'],
            $validator->getData(),
        );
    }

    public function testExcludeUnlessWithWildcardConditionField(): void
    {
        $v = $this->makeValidator(
            ['items' => [
                ['type' => 'chapter', 'position' => 5, 'label' => 'First'],
                ['type' => 'section', 'position' => 10, 'label' => 'Second'],
            ]],
            [
                'items.*.type' => 'required|string',
                'items.*.position' => 'exclude_unless:items.*.type,chapter|required|integer',
                'items.*.label' => 'exclude_unless:items.*.type,chapter|required|string',
            ],
        );

        $this->assertTrue($v->passes());
        $validated = $v->validated();
        $this->assertArrayHasKey('position', $validated['items'][0]);
        $this->assertArrayHasKey('label', $validated['items'][0]);
        $this->assertArrayNotHasKey('position', $validated['items'][1]);
        $this->assertArrayNotHasKey('label', $validated['items'][1]);
    }

    public function testBooleanConditionMatchesExecutionSemantics(): void
    {
        $v = $this->makeValidator(
            ['active' => true, 'details' => 'some details'],
            ['active' => 'required|boolean', 'details' => 'exclude_unless:active,true|required|string'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayHasKey('details', $v->validated());
    }

    public function testNullConditionMatchesExecutionSemantics(): void
    {
        $v = $this->makeValidator(
            ['type' => null, 'details' => 'some details'],
            ['details' => 'exclude_if:type,null|string'],
        );

        $v->passes();
        $this->assertArrayNotHasKey('details', $v->validated());
    }

    public function testLaterExclusionDoesNotEraseAnEarlierFailure(): void
    {
        $v = $this->makeValidator(
            ['type' => 'draft', 'publish_date' => 'not-an-integer'],
            ['publish_date' => 'integer|exclude_if:type,draft'],
        );

        $this->assertFalse($v->passes());
        $this->assertTrue($v->errors()->has('publish_date'));
        $this->assertArrayHasKey('Integer', $v->failed()['publish_date']);
    }

    public function testFirstPositionUnconditionalExcludeRemovesTheAttribute(): void
    {
        $v = $this->makeValidator(
            ['secret' => 'value'],
            ['secret' => 'exclude|required|string'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('secret', $v->validated());
    }

    public function testFirstPositionExclusionSupportsTopLevelNumericAttributes(): void
    {
        $v = $this->makeValidator(
            [0 => 'value'],
            [0 => 'exclude|required|string'],
        );

        $this->assertTrue($v->passes());
        $this->assertSame([], $v->validated());
    }

    public function testFirstPositionExcludeWithRemovesTheAttribute(): void
    {
        $v = $this->makeValidator(
            ['trigger' => true, 'details' => 'value'],
            ['details' => 'exclude_with:trigger|required|string'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('details', $v->validated());
    }

    public function testFirstPositionExcludeWithoutRemovesTheAttribute(): void
    {
        $v = $this->makeValidator(
            ['details' => 'value'],
            ['details' => 'exclude_without:trigger|required|string'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('details', $v->validated());
    }

    public function testPlanFlagsDoNotDisplaceTheFirstExecutableExclusion(): void
    {
        $v = $this->makeValidator(
            ['type' => 'draft', 'details' => 'value'],
            ['details' => 'bail|nullable|sometimes|exclude_if:type,draft|required|string'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('details', $v->validated());
    }

    public function testMalformedExclusionIsDeferredUntilNormalExecution(): void
    {
        $v = $this->makeValidator(
            ['first' => 'invalid', 'details' => 'value'],
            [
                'first' => 'integer',
                'details' => 'exclude_if:type|required|string',
            ],
        )->stopOnFirstFailure();

        $this->assertFalse($v->passes());
        $this->assertTrue($v->errors()->has('first'));
    }

    public function testMalformedExclusionStillThrowsWhenExecutionReachesIt(): void
    {
        $v = $this->makeValidator(
            ['details' => 'value'],
            ['details' => 'exclude_if:type|required|string'],
        );

        $this->expectException(InvalidArgumentException::class);

        $v->passes();
    }

    public function testMalformedWildcardExclusionDoesNotOverrideAnEarlierStop(): void
    {
        $v = $this->makeValidator(
            ['first' => 'invalid', 'items' => [['details' => 'value']]],
            [
                'first' => 'integer',
                'items.*.details' => 'exclude_if:groups.*.*.type,chapter|required|string',
            ],
        )->stopOnFirstFailure();

        $this->assertFalse($v->passes());
        $this->assertTrue($v->errors()->has('first'));
    }

    public function testLiteralNumericSegmentsAreNotMistakenForWildcardCaptures(): void
    {
        $v = $this->makeValidator(
            ['data' => [5 => ['items' => [['type' => 'section', 'value' => 'invalid']]]]],
            ['data.5.items.*.value' => 'exclude_unless:data.5.items.*.type,chapter|required|integer'],
        );

        $this->assertTrue($v->passes());
        $this->assertSame([], $v->validated());
    }

    public function testUnusedCustomExtensionDoesNotDisablePreEvaluation(): void
    {
        $v = $this->makeValidator(
            ['type' => 'section', 'appointments' => [['name' => 123]]],
            [
                'appointments' => 'exclude_unless:type,chapter|required|array',
                'appointments.*.name' => 'required|string',
            ],
        );

        $v->addExtension('unused', function (): bool {
            return true;
        });

        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('appointments', $v->validated());
    }

    public function testPlainRuleObjectDoesNotDisablePreEvaluation(): void
    {
        $calls = 0;
        $customRule = new class($calls) implements RuleContract {
            public function __construct(private int &$calls)
            {
            }

            public function passes(string $attribute, mixed $value): bool
            {
                ++$this->calls;

                return true;
            }

            public function message(): array|string
            {
                return '';
            }
        };

        $v = $this->makeValidator(
            ['type' => 'section', 'appointments' => [['name' => 'test']]],
            [
                'appointments' => 'exclude_unless:type,chapter|required|array',
                'appointments.*.name' => [$customRule],
            ],
        );

        $this->assertTrue($v->passes());
        $this->assertSame(0, $calls);
    }

    public function testPlainModernRuleDoesNotDisablePreEvaluation(): void
    {
        $calls = 0;
        $customRule = new class($calls) implements ValidationRule {
            public function __construct(private int &$calls)
            {
            }

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                ++$this->calls;
            }
        };

        $v = $this->makeValidator(
            ['type' => 'section', 'appointments' => [['name' => 'test']]],
            [
                'appointments' => 'exclude_unless:type,chapter|required|array',
                'appointments.*.name' => [$customRule],
            ],
        );

        $this->assertTrue($v->passes());
        $this->assertSame(0, $calls);
    }

    public function testUsedExtensionDefersExclusionUntilAfterItsMutation(): void
    {
        $v = $this->makeValidator(
            ['prepare' => true, 'type' => 'section', 'details' => 'value'],
            [
                'prepare' => 'prepare_type',
                'details' => 'exclude_unless:type,chapter|required|string',
            ],
        );
        $v->addExtension(
            'prepare_type',
            function (string $attribute, mixed $value, array $parameters, Validator $validator): bool {
                $validator->setValue('type', 'chapter');

                return true;
            },
        );

        $this->assertTrue($v->passes());
        $this->assertArrayHasKey('details', $v->validated());
    }

    public function testClosureRuleDefersExclusionUntilAfterItsMutation(): void
    {
        $v = $this->makeValidator(
            ['prepare' => true, 'type' => 'section', 'details' => 'value'],
            [
                'prepare' => [function (string $attribute, mixed $value, Closure $fail, Validator $validator): void {
                    $validator->setValue('type', 'chapter');
                }],
                'details' => 'exclude_unless:type,chapter|required|string',
            ],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayHasKey('details', $v->validated());
    }

    public function testDirectValidatorAwareRuleDefersExclusionUntilAfterItsMutation(): void
    {
        $customRule = new class implements RuleContract, ValidatorAwareRule {
            private Validator $validator;

            public function setValidator(Validator $validator): static
            {
                $this->validator = $validator;

                return $this;
            }

            public function passes(string $attribute, mixed $value): bool
            {
                $this->validator->setValue('type', 'chapter');

                return true;
            }

            public function message(): string
            {
                return '';
            }
        };
        $v = $this->makeValidator(
            ['prepare' => true, 'type' => 'section', 'details' => 'value'],
            [
                'prepare' => [$customRule],
                'details' => 'exclude_unless:type,chapter|required|string',
            ],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayHasKey('details', $v->validated());
    }

    public function testWrappedValidatorAwareRuleDefersExclusionUntilAfterItsMutation(): void
    {
        $customRule = new class implements ValidationRule, ValidatorAwareRule {
            private Validator $validator;

            public function setValidator(Validator $validator): static
            {
                $this->validator = $validator;

                return $this;
            }

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                $this->validator->setValue('type', 'chapter');
            }
        };
        $v = $this->makeValidator(
            ['prepare' => true, 'type' => 'section', 'details' => 'value'],
            [
                'prepare' => [$customRule],
                'details' => 'exclude_unless:type,chapter|required|string',
            ],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayHasKey('details', $v->validated());
    }

    public function testMultipleExcludeConditionsOnDifferentAttributes(): void
    {
        $v = $this->makeValidator(
            [
                'type' => 'section',
                'field_a' => 'value_a',
                'field_b' => 'value_b',
            ],
            [
                'type' => 'required|string',
                'field_a' => 'exclude_unless:type,chapter|required|string',
                'field_b' => 'exclude_unless:type,article|required|string',
            ],
        );

        $this->assertTrue($v->passes());
        $validated = $v->validated();
        $this->assertArrayNotHasKey('field_a', $validated);
        $this->assertArrayNotHasKey('field_b', $validated);
    }

    public function testPreExcludedWildcardAttributesRemovedFromValidatedOutput(): void
    {
        $items = [];
        for ($i = 0; $i < 50; ++$i) {
            $items[] = ['type' => $i % 5 === 0 ? 'chapter' : 'section', 'detail' => 'value'];
        }

        $v = $this->makeValidator(
            ['items' => $items],
            [
                'items.*.type' => 'required|string',
                'items.*.detail' => 'exclude_unless:items.*.type,chapter|required|string',
            ],
        );

        $this->assertTrue($v->passes());
        $validated = $v->validated();

        // Chapter items (indices 0, 5, 10, ...) should have 'detail'
        $this->assertArrayHasKey('detail', $validated['items'][0]);
        $this->assertArrayHasKey('detail', $validated['items'][5]);

        // Section items should NOT have 'detail'
        $this->assertArrayNotHasKey('detail', $validated['items'][1]);
        $this->assertArrayNotHasKey('detail', $validated['items'][2]);
    }

    public function testPreExcludedParentExcludesDescendantAttributes(): void
    {
        $v = $this->makeValidator(
            [
                'type' => 'section',
                'appointments' => [
                    ['date' => 'not-a-date', 'name' => 123],
                ],
            ],
            [
                'type' => 'required|string',
                'appointments' => 'exclude_unless:type,chapter|required|array',
                'appointments.*.date' => 'required|date',
                'appointments.*.name' => 'required|string',
            ],
        );

        $this->assertTrue($v->passes());
        $validated = $v->validated();
        $this->assertArrayNotHasKey('appointments', $validated);
    }

    public function testExecutionTimeParentExclusionSkipsLaterDescendants(): void
    {
        foreach ([Validator::class, DelegatedExclusionValidator::class] as $validatorClass) {
            $validator = $this->makeValidator(
                ['parent' => ['child' => 'invalid'], 'flag' => 'yes'],
                [
                    'parent' => 'array|exclude_if:flag,yes',
                    'parent.child' => 'integer',
                ],
                $validatorClass,
            );

            $this->assertTrue($validator->passes(), $validatorClass);
            $this->assertSame([], $validator->errors()->toArray(), $validatorClass);
            $this->assertSame(['flag' => 'yes'], $validator->getData(), $validatorClass);
        }
    }

    public function testDescendantBeforeParentKeepsItsEarlierFailure(): void
    {
        foreach ([Validator::class, DelegatedExclusionValidator::class] as $validatorClass) {
            $validator = $this->makeValidator(
                ['parent' => ['child' => 'invalid'], 'flag' => 'yes'],
                [
                    'parent.child' => 'integer',
                    'parent' => 'exclude_if:flag,yes',
                ],
                $validatorClass,
            );

            $this->assertFalse($validator->passes(), $validatorClass);
            $this->assertTrue($validator->errors()->has('parent.child'), $validatorClass);
            $this->assertSame(['flag' => 'yes'], $validator->getData(), $validatorClass);
        }
    }

    public function testGlobalEarlyStopDoesNotActivateALaterExclusionHint(): void
    {
        $validator = $this->makeValidator(
            ['first' => 'invalid', 'secret' => 'value'],
            ['first' => 'integer', 'secret' => 'exclude'],
        )->stopOnFirstFailure();

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('first'));
        $this->assertSame(['first' => 'invalid', 'secret' => 'value'], $validator->getData());
    }

    public function testAbsentSometimesAttributeStillRunsLaterExclusion(): void
    {
        foreach ([Validator::class, DelegatedExclusionValidator::class] as $validatorClass) {
            $validator = $this->makeValidator(
                ['flag' => 'yes'],
                [
                    'parent' => 'sometimes|string|exclude_if:flag,yes',
                    'parent.child' => 'required',
                ],
                $validatorClass,
            );

            $this->assertTrue($validator->passes(), $validatorClass);
            $this->assertSame([], $validator->errors()->toArray(), $validatorClass);
            $this->assertArrayNotHasKey('parent', $validator->getRules(), $validatorClass);
            $this->assertArrayNotHasKey('parent.child', $validator->getRules(), $validatorClass);
        }
    }

    public function testGlobalEarlyStopDoesNotConvertALaterNonScalarExclusionParameter(): void
    {
        $field = new ValidationExclusionStringable('flag');
        $validator = $this->makeValidator(
            ['first' => 'invalid', 'flag' => 'yes', 'target' => 'value'],
            [
                'first' => 'integer',
                'target' => [['exclude_if', $field, 'yes'], 'string'],
            ],
        )->stopOnFirstFailure();

        $this->assertFalse($validator->passes());
        $this->assertSame(0, $field->casts);
        $this->assertSame(['Integer'], array_keys($validator->failed()['first']));
        $this->assertSame(
            ['first' => 'invalid', 'flag' => 'yes', 'target' => 'value'],
            $validator->getData(),
        );
    }

    public function testReachedNonScalarExclusionParameterUsesOrdinaryConversionOnce(): void
    {
        $field = new ValidationExclusionStringable('flag');
        $validator = $this->makeValidator(
            ['flag' => 'yes', 'target' => 'value'],
            ['target' => [['exclude_if', $field, 'yes'], 'string']],
        );

        $this->assertTrue($validator->passes());
        $this->assertSame(1, $field->casts);
        $this->assertSame(['flag' => 'yes'], $validator->getData());
    }

    public function testLaterExclusionUsesDataAfterExcludedDescendantRemoval(): void
    {
        $validator = $this->makeValidator(
            ['parent' => ['child' => 'value'], 'later' => 'invalid'],
            [
                'parent' => 'exclude',
                'parent.child' => 'string',
                'later' => 'exclude_if:parent.child,value|integer',
            ],
        );

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('later'));
        $this->assertSame(['later' => 'invalid'], $validator->getData());
    }

    public function testLaterExclusionCanStillReadExcludedParentUntilFinalCleanup(): void
    {
        $validator = $this->makeValidator(
            ['parent' => ['child' => 'value'], 'later' => 'invalid'],
            [
                'parent' => 'exclude',
                'later' => 'exclude_if:parent.child,value|integer',
            ],
        );

        $this->assertTrue($validator->passes());
        $this->assertSame([], $validator->getData());
    }

    public function testLaterDependentRuleCanReadExcludedParentUntilFinalCleanup(): void
    {
        $validator = $this->makeValidator(
            ['parent' => ['child' => 'value'], 'later' => 'invalid'],
            [
                'parent' => 'exclude',
                'later' => 'required_with:parent|integer',
            ],
        );

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('later'));
        $this->assertSame(['later' => 'invalid'], $validator->getData());
    }

    public function testLaterPositionExclusionUsesInternalEscapedDotKey(): void
    {
        $laterRuleCalls = 0;
        $validator = $this->makeValidator(
            ['literal.dot' => 'invalid'],
            [
                'literal\.dot' => [
                    'integer',
                    'exclude',
                    function () use (&$laterRuleCalls): bool {
                        ++$laterRuleCalls;

                        return false;
                    },
                ],
            ],
        );

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('literal.dot'));
        $this->assertSame(0, $laterRuleCalls);
        $this->assertSame([], $validator->getData());
    }

    /**
     * @param class-string<Validator> $validatorClass
     */
    private function makeValidator(
        array $data,
        array $rules,
        string $validatorClass = Validator::class,
    ): Validator {
        return new $validatorClass(
            new Translator(new ArrayLoader, 'en'),
            $data,
            $rules,
        );
    }
}

class DelegatedExclusionValidator extends Validator
{
}

class ValidationExclusionStringable implements Stringable
{
    public int $casts = 0;

    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        ++$this->casts;

        return $this->value;
    }
}
