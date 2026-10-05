<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation;

use Attribute;
use Closure;
use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Response as AuthorizationResponse;
use Hypervel\Container\Attributes\Config;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Contracts\Validation\ValidationRule as ValidationRuleContract;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MergeValidationRules;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Attributes\Validation\ArrayType;
use Hypervel\Data\Attributes\Validation\Confirmed;
use Hypervel\Data\Attributes\Validation\CustomValidationAttribute;
use Hypervel\Data\Attributes\Validation\Distinct;
use Hypervel\Data\Attributes\Validation\Enum as EnumAttribute;
use Hypervel\Data\Attributes\Validation\ExcludeIf;
use Hypervel\Data\Attributes\Validation\Max;
use Hypervel\Data\Attributes\Validation\Min;
use Hypervel\Data\Attributes\Validation\Required;
use Hypervel\Data\Attributes\Validation\RequiredUnless;
use Hypervel\Data\Attributes\Validation\RequiredWith;
use Hypervel\Data\Attributes\Validation\Rule;
use Hypervel\Data\Attributes\Validation\Sometimes;
use Hypervel\Data\Attributes\Validation\StringType;
use Hypervel\Data\Attributes\WithoutValidation;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Exceptions\CannotCreateAbstractClass;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Optional;
use Hypervel\Data\Resource;
use Hypervel\Data\RuleInferrers\RuleInferrer;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Validation\PropertyRules;
use Hypervel\Data\Support\Validation\References\ContainerReference;
use Hypervel\Data\Support\Validation\ValidationContext;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Http\Attributes\ErrorBag;
use Hypervel\Foundation\Http\Attributes\FailOnUnknownFields;
use Hypervel\Foundation\Http\Attributes\RedirectTo;
use Hypervel\Foundation\Http\Attributes\RedirectToRoute;
use Hypervel\Foundation\Http\Attributes\StopOnFirstFailure;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Validation\Factory as ValidationFactory;
use Hypervel\Validation\Rules\Enum as EnumRule;
use Hypervel\Validation\ValidationException;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function Hypervel\Coroutine\parallel;

class DataValidatorTest extends TestCase
{
    /**
     * Get package providers for the validation test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Test request-only validation leaves ordinary arrays on the lean path.
     */
    public function testOnlyRequestsValidationStrategyKeepsArrayCreationLean(): void
    {
        // PHP converts '1e3', while the integer rule would reject it.
        $arrayData = ValidatedDataFixture::from(['id' => '1e3']);

        $this->assertSame(1000, $arrayData->id);

        $this->expectException(ValidationException::class);

        ValidatedDataFixture::from(Request::create('/', 'POST', ['id' => 'invalid']));
    }

    /**
     * Test all three base classes share the request-only validation default.
     */
    public function testBaseClassesShareRequestOnlyValidationByDefault(): void
    {
        $this->assertSame(1000, ValidatedDtoFixture::from(['id' => '1e3'])->id);
        $this->assertSame(1000, ValidatedResourceFixture::from(['id' => '1e3'])->id);

        foreach ([ValidatedDtoFixture::class, ValidatedResourceFixture::class] as $class) {
            try {
                $class::from(Request::create('/', 'POST', ['id' => '1e3']));
                $this->fail("Expected {$class} Request validation to fail.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('id', $exception->errors());
            }
        }
    }

    /**
     * Test validation-only mode returns uncast validated input.
     */
    public function testValidateReturnsValidatedPayloadWithoutCasting(): void
    {
        $validated = ValidatedDataFixture::validate(['id' => '12']);

        $this->assertSame(['id' => '12'], $validated);
    }

    /**
     * Test validate-and-create casts only after validation succeeds.
     */
    public function testValidateAndCreateUsesTheSameRulesBeforeCasting(): void
    {
        $data = ValidatedDataFixture::validateAndCreate(['id' => '12']);

        $this->assertSame(12, $data->id);
    }

    /**
     * Test a confirmation rule reads its undeclared confirmation field.
     */
    public function testConfirmedRuleReadsItsUndeclaredConfirmationField(): void
    {
        $data = ConfirmedPasswordDataFixture::validateAndCreate([
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $this->assertSame('secret123', $data->password);

        try {
            ConfirmedPasswordDataFixture::validateAndCreate([
                'password' => 'secret123',
                'password_confirmation' => 'different',
            ]);
            $this->fail('Expected a mismatched confirmation to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }
    }

    /**
     * Test rules may reference undeclared input that the validated payload omits.
     */
    public function testRulesReferenceUndeclaredInputWithoutReturningIt(): void
    {
        try {
            ConditionalNameDataFixture::validate(['mode' => 'admin']);
            $this->fail('Expected the undeclared condition to require the name.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        $this->assertSame(
            ['name' => 'Taylor'],
            ConditionalNameDataFixture::validate(['mode' => 'admin', 'name' => 'Taylor']),
        );
    }

    /**
     * Test undeclared input beside a dot-mapped property remains available to its rules.
     */
    public function testUndeclaredInputBesideADotMappedPropertyRemainsAvailable(): void
    {
        try {
            MappedConditionalNameDataFixture::validate(['profile' => ['mode' => 'strict']]);
            $this->fail('Expected the sibling condition to require the mapped name.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('profile.name', $exception->errors());
        }

        $this->assertSame(
            ['profile' => ['name' => 'Taylor']],
            MappedConditionalNameDataFixture::validate(['profile' => ['mode' => 'strict', 'name' => 'Taylor']]),
        );
    }

    public function testDataCollectionsMustBePresentButMayBeEmpty(): void
    {
        $expected = [
            'items' => ['present', 'array'],
            'nullableItems' => ['nullable', 'array'],
            'optionalItems' => ['sometimes', 'array'],
            'requiredItems' => ['array', 'required'],
            'plain' => ['required', 'array'],
        ];

        $this->assertSame($expected, PresentCollectionDataFixture::getValidationRules([]));

        $valid = ['items' => [], 'requiredItems' => [['string' => 'a']], 'plain' => ['a']];

        $this->assertSame($valid, PresentCollectionDataFixture::validate($valid));

        foreach ([
            'items' => ['requiredItems' => [['string' => 'a']], 'plain' => ['a']],
            'requiredItems' => ['items' => [], 'requiredItems' => [], 'plain' => ['a']],
            'plain' => ['items' => [], 'requiredItems' => [['string' => 'a']], 'plain' => []],
        ] as $errorKey => $payload) {
            try {
                PresentCollectionDataFixture::validate($payload);
                $this->fail("Expected a validation error for [{$errorKey}].");
            } catch (ValidationException $exception) {
                $this->assertSame([$errorKey], array_keys($exception->errors()));
            }
        }
    }

    #[WithConfig('data.rule_inferrers', [MaxStringRuleInferrer::class])]
    public function testConfiguredRuleInferrersKeepDataCollectionPresence(): void
    {
        $rules = PresentCollectionDataFixture::getValidationRules([]);

        $this->assertSame(['present', 'array'], $rules['items']);
        $this->assertSame(['array', 'required'], $rules['requiredItems']);
    }

    public function testClassRulesMergeWithOrReplaceDataCollectionPresence(): void
    {
        $this->assertSame(
            ['present', 'array', 'required'],
            MergedRequiredCollectionDataFixture::getValidationRules([])['items'],
        );

        try {
            MergedRequiredCollectionDataFixture::validate(['items' => []]);
            $this->fail('Expected the merged required rule to reject an empty collection.');
        } catch (ValidationException $exception) {
            $this->assertSame(['items'], array_keys($exception->errors()));
        }

        $this->assertSame(['array'], ReplacedCollectionRulesDataFixture::getValidationRules([])['items']);
        $this->assertSame([], ReplacedCollectionRulesDataFixture::validate([]));
    }

    public function testUnionValuesUseTheTypeRuleOfTheTypeThatHoldsThem(): void
    {
        $data = UnionTypeRuleDataFixture::validateAndCreate([
            'stringOrData' => 'Hello',
            'intOrData' => 10,
            'container' => new Collection(['a']),
            'collectionOrString' => 'x',
        ]);

        $this->assertSame('Hello', $data->stringOrData);
        $this->assertSame(10, $data->intOrData);
        $this->assertSame(['a'], $data->container->all());
        $this->assertSame('x', $data->collectionOrString);

        // The integer rule keeps the size rule numeric, and an object normalized for the data type keeps its rules.
        foreach ([
            'intOrData' => ['intOrData' => 3],
            'arrayOrData.string' => ['arrayOrData' => (object) ['other' => 'value']],
        ] as $errorKey => $payload) {
            try {
                UnionTypeRuleDataFixture::validateAndCreate($payload);
                $this->fail("Expected a validation error for [{$errorKey}].");
            } catch (ValidationException $exception) {
                $this->assertSame([$errorKey], array_keys($exception->errors()));
            }
        }
    }

    /**
     * Test a backed-enum property infers the enum rule, so invalid request input fails validation instead of the cast.
     */
    public function testBackedEnumPropertiesInferTheEnumRule(): void
    {
        $this->assertEquals(
            ['required', new EnumRule(ValidationStatusEnumFixture::class)],
            EnumValidatedDataFixture::getValidationRules([])['status'],
        );

        try {
            EnumValidatedDataFixture::from(Request::create('/', 'POST', ['status' => 'invalid', 'priority' => '1']));
            $this->fail('Expected the invalid enum value to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['status'], array_keys($exception->errors()));
        }

        // Integral numeric strings select an integer-backed case, as the enum cast accepts them.
        foreach (['1', '1.0'] as $priority) {
            $data = EnumValidatedDataFixture::from(
                Request::create('/', 'POST', ['status' => 'active', 'priority' => $priority]),
            );

            $this->assertSame(ValidationStatusEnumFixture::Active, $data->status);
            $this->assertSame(ValidationPriorityEnumFixture::High, $data->priority);
        }
    }

    /**
     * Test a declared enum rule, with its case restrictions, replaces the inferred one.
     */
    public function testDeclaredEnumRulesReplaceTheInferredEnumRule(): void
    {
        $rules = RestrictedEnumDataFixture::getValidationRules([])['status'];

        $this->assertCount(1, array_filter($rules, static fn (mixed $rule): bool => $rule instanceof EnumRule));

        try {
            RestrictedEnumDataFixture::validate(['status' => 'inactive']);
            $this->fail('Expected the excluded case to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['status'], array_keys($exception->errors()));
        }
    }

    /**
     * Test identical dynamic items keep one wildcard enum rule, while different explicit restrictions stay per item.
     */
    public function testEnumRulesKeepUniformCollectionsWildcard(): void
    {
        $rules = EnumItemsParentDataFixture::getValidationRules([
            'items' => [['status' => 'active'], ['status' => 'inactive']],
        ]);

        $this->assertArrayHasKey('items.*.status', $rules);
        $this->assertArrayNotHasKey('items.0.status', $rules);

        $rules = EnumItemsParentDataFixture::getValidationRules([
            'items' => [
                ['status' => 'active', 'only' => 'active'],
                ['status' => 'inactive', 'only' => 'inactive'],
            ],
        ]);

        $this->assertArrayHasKey('items.0.status', $rules);
        $this->assertArrayHasKey('items.1.status', $rules);
    }

    /**
     * Test configured rule inferrers adjust attribute and inferred rules.
     */
    #[WithConfig('data.rule_inferrers', [MaxStringRuleInferrer::class, OptionalNicknameRuleInferrer::class])]
    public function testConfiguredRuleInferrersAdjustTheInferredRules(): void
    {
        $rules = RuleInferrerDataFixture::getValidationRules([]);

        $this->assertSame(['required', 'string', 'max:255'], $rules['name']);
        $this->assertSame(['string', 'max:255', 'sometimes'], $rules['nickname']);
        $this->assertSame(['required', 'string', 'max:20'], $rules['code']);
        $this->assertSame(['required', 'array:id'], $rules['meta']);
        $this->assertSame(['required', 'integer'], $rules['age']);
        // A Rule attribute's string rules reach the inferrers as typed rules.
        $this->assertSame(['required', 'string', 'max:255'], $rules['ruled']);
    }

    /**
     * Test declared attributes replace the inferred rule of their type without configured inferrers too.
     */
    public function testDeclaredRulesReplaceTheInferredRuleOfTheirType(): void
    {
        $rules = RuleInferrerDataFixture::getValidationRules([]);

        $this->assertSame(['required', 'string', 'max:20'], $rules['code']);
        $this->assertSame(['required', 'array:id'], $rules['meta']);
        $this->assertSame(['required', 'string'], $rules['ruled']);
    }

    /**
     * Test merged class presence rules replace a requirement left by the inferrers.
     */
    #[WithConfig('data.rule_inferrers', [MaxStringRuleInferrer::class])]
    public function testMergedClassPresenceRulesStillReplaceAnInferredRequirement(): void
    {
        $rules = MergedRuleInferrerDataFixture::getValidationRules([]);

        $this->assertSame(['string', 'max:255', 'required_with:other'], $rules['name']);
    }

    /**
     * Test rule inferrers receive each collection item's payload.
     */
    #[WithConfig('data.rule_inferrers', [PayloadLengthRuleInferrer::class])]
    public function testRuleInferrersReceiveEachCollectionItemPayload(): void
    {
        $rules = RuleInferrerParentDataFixture::getValidationRules([
            'items' => [['name' => 'Taylor'], ['name' => 'Swift', 'long' => true]],
        ]);

        $this->assertSame(['required', 'string', 'max:5'], $rules['items.0.name']);
        $this->assertSame(['required', 'string', 'max:100'], $rules['items.1.name']);
        $this->assertSame([], $rules['items.*.name']);
    }

    /**
     * Test rule inferrers see each collection item's own input path, while uniform items keep one wildcard rule.
     */
    #[WithConfig('data.rule_inferrers', [RecordingContextRuleInferrer::class])]
    public function testRuleInferrersReceiveEachCollectionItemPath(): void
    {
        RecordingContextRuleInferrer::$contexts = [];

        $rules = RuleInferrerParentDataFixture::getValidationRules([
            'items' => [['name' => 'Taylor'], ['name' => 'Swift']],
        ]);

        $this->assertSame(
            [
                'items' => [''],
                'name' => ['items.0', 'items.1'],
                'long' => ['items.0', 'items.1'],
            ],
            array_map(
                static fn (array $contexts): array => array_map(
                    static fn (ValidationContext $context): string => $context->path->get(),
                    $contexts,
                ),
                RecordingContextRuleInferrer::$contexts,
            ),
        );
        $this->assertArrayHasKey('items.*.name', $rules);
        $this->assertArrayNotHasKey('items.0.name', $rules);
    }

    /**
     * Test rule inferrers for a missing or null child and an empty collection's item template receive no input.
     */
    #[WithConfig('data.rule_inferrers', [RecordingContextRuleInferrer::class])]
    public function testRuleInferrersReceiveNoInputForUnobservedNodes(): void
    {
        foreach ([[], ['child' => null]] as $child) {
            RecordingContextRuleInferrer::$contexts = [];
            $payload = [...$child, 'children' => []];

            UnreadableParentDataFixture::getValidationRules($payload);

            $this->assertSame(
                [['child', [], $payload], ['children.*', [], $payload]],
                array_map(
                    static fn (ValidationContext $context): array => [
                        $context->path->get(),
                        $context->payload,
                        $context->fullPayload,
                    ],
                    RecordingContextRuleInferrer::$contexts['name'],
                ),
            );
        }
    }

    /**
     * Test scoped rule inferrers are resolved for each compilation.
     */
    #[WithConfig('data.rule_inferrers', [ScopedMaxRuleInferrer::class])]
    public function testScopedRuleInferrersFollowTheCurrentCoroutine(): void
    {
        $this->app->scoped(
            ScopedMaxRuleInferrer::class,
            static fn (): ScopedMaxRuleInferrer => new ScopedMaxRuleInferrer(
                CoroutineContext::get('__data.test.max'),
            ),
        );

        $nameRules = static function (int $max): array {
            CoroutineContext::set('__data.test.max', $max);
            usleep(5000);

            return RuleInferrerDataFixture::getValidationRules([])['name'];
        };

        [$first, $second] = parallel([
            static fn (): array => $nameRules(10),
            static fn (): array => $nameRules(20),
        ]);

        $this->assertSame(['required', 'string', 'max:10'], $first);
        $this->assertSame(['required', 'string', 'max:20'], $second);
    }

    /**
     * Test a rule removed by an inferrer never resolves its references.
     */
    #[WithConfig('data.rule_inferrers', [RemoveMaxRuleInferrer::class])]
    public function testRuleInferrersRemoveRulesBeforeTheirReferencesResolve(): void
    {
        $rules = UnboundReferenceDataFixture::getValidationRules([]);

        $this->assertSame(['required', 'string'], $rules['name']);
    }

    /**
     * Test rules that survive the inferrers resolve their values once.
     */
    #[WithConfig('data.rule_inferrers', [MaxStringRuleInferrer::class])]
    public function testRulesSurvivingInferrersResolveOnce(): void
    {
        $resolutions = 0;
        $this->app->bind('counted-limit', static function () use (&$resolutions): int {
            ++$resolutions;

            return 10;
        });
        CountingRuleAttribute::$evaluations = 0;

        $rules = CountedReferenceDataFixture::getValidationRules([]);

        $this->assertSame(['required', 'string', 'max:10', 'alpha'], $rules['name']);
        $this->assertSame(1, $resolutions);
        $this->assertSame(1, CountingRuleAttribute::$evaluations);
    }

    /**
     * Test class rules and rule inferrers receive undeclared input at every level.
     */
    #[WithConfig('data.rule_inferrers', [RecordingContextRuleInferrer::class])]
    public function testValidationContextsIncludeUndeclaredInput(): void
    {
        RecordingContextRuleInferrer::$contexts = [];
        ContextParentDataFixture::$contexts = [];
        $payload = ['mode' => 'admin', 'child' => ['kind' => 'primary', 'value' => 'a']];

        ContextParentDataFixture::getValidationRules($payload);

        $this->assertSame([$payload, $payload], ContextParentDataFixture::$contexts['parent']);
        $this->assertSame([$payload['child'], $payload], ContextParentDataFixture::$contexts['child']);
        [$valueContext] = RecordingContextRuleInferrer::$contexts['value'];
        $this->assertSame([$payload['child'], $payload], [$valueContext->payload, $valueContext->fullPayload]);
    }

    /**
     * Test validated collection items read only the mapped wire key and report errors at its path.
     */
    public function testValidatesNestedDataCollectionsWithMappedWireKeys(): void
    {
        try {
            ValidatedParentDataFixture::factory()
                ->alwaysValidate()
                ->from([
                    'children' => [
                        ['profile' => ['name' => 123]],
                        // Under the PHP property name, the short value is neither read nor validated.
                        ['name' => 'Tay'],
                    ],
                ]);
            $this->fail('Expected nested validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['children.0.profile.name'], array_keys($exception->errors()));
        }
    }

    /**
     * Test validated sources read only the mapped name, so a later source's PHP property name is not read.
     */
    public function testValidatedSourcesReadOnlyTheMappedName(): void
    {
        $data = MappedValueDataFixture::factory()
            ->alwaysValidate()
            ->from(['wire' => 'first'], ['value' => 'second']);

        $this->assertSame('first', $data->value);

        // Without validation, the PHP property name remains a fallback, as in Spatie.
        $this->assertSame('second', MappedValueDataFixture::from(['value' => 'second'])->value);
    }

    /**
     * Test an Optional mapped property ignores validated input under its PHP property name.
     */
    public function testValidatedOptionalMappedPropertiesIgnoreThePropertyName(): void
    {
        $data = OptionalMappedValueDataFixture::validateAndCreate(['value' => 'ignored']);

        $this->assertInstanceOf(Optional::class, $data->value);
        $this->assertSame([], $data->toArray());
    }

    /**
     * Test strict validation reports a PHP property name sent in place of the mapped name.
     */
    public function testFailOnUnknownFieldsRejectsThePropertyNameOfAMappedProperty(): void
    {
        try {
            StrictMappedValueDataFixture::validateAndCreate(['value' => 'first']);
            $this->fail('Expected the PHP property name to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['wire', 'value'], array_keys($exception->errors()));
        }
    }

    /**
     * Test validation materializes lazy data items into an array declaration.
     */
    public function testValidationMaterializesLazyCollectionForArrayProperty(): void
    {
        $data = ValidatedParentDataFixture::validateAndCreate([
            'children' => LazyCollection::make([
                ['profile' => ['name' => 'Taylor']],
            ]),
        ]);

        $this->assertIsArray($data->children);
        $this->assertInstanceOf(ValidatedChildDataFixture::class, $data->children[0]);
    }

    /**
     * Test validation rebuilds a declared LazyCollection after materializing it.
     */
    public function testValidationRebuildsDeclaredLazyCollection(): void
    {
        $data = ValidatedLazyParentDataFixture::validateAndCreate([
            'children' => LazyCollection::make([
                ['profile' => ['name' => 'Taylor']],
            ]),
        ]);

        $this->assertInstanceOf(LazyCollection::class, $data->children);
        $this->assertInstanceOf(
            ValidatedChildDataFixture::class,
            $data->children->first(),
        );
    }

    /**
     * Test rule introspection materializes lazy collections for nested rules.
     */
    public function testRuleIntrospectionMaterializesLazyCollections(): void
    {
        $children = [
            ['profile' => ['name' => 'Taylor']],
            ['profile' => ['name' => 'Abigail']],
        ];
        $arrayRules = ValidatedLazyParentDataFixture::getValidationRules([
            'children' => $children,
        ]);
        $lazyRules = ValidatedLazyParentDataFixture::getValidationRules([
            'children' => LazyCollection::make($children),
        ]);

        $this->assertSame($arrayRules, $lazyRules);
        $this->assertArrayHasKey('children.*.profile.name', $lazyRules);
    }

    /**
     * Test a class wildcard rule keyed by PHP property names applies at each item's mapped wire path.
     */
    public function testTranslatesClassWildcardRulesToMappedWirePaths(): void
    {
        try {
            ValidatedParentDataFixture::validateAndCreate([
                'children' => [
                    ['profile' => ['name' => 'one']],
                    ['profile' => ['name' => 'two']],
                ],
            ]);
            $this->fail('Expected nested class rules to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['children.0.profile.name', 'children.1.profile.name'],
                array_keys($exception->errors()),
            );
        }
    }

    /**
     * Test uniform static collections compile one wildcard rule template.
     */
    public function testUniformStaticCollectionUsesWildcardRules(): void
    {
        $rules = ValidatedParentDataFixture::getValidationRules([
            'children' => [
                ['profile' => ['name' => 'Taylor']],
                ['profile' => ['name' => 'Swift']],
            ],
        ]);

        $this->assertArrayHasKey('children.*.profile.name', $rules);
        $this->assertArrayNotHasKey('children.0.profile.name', $rules);
        $this->assertArrayNotHasKey('children.1.profile.name', $rules);
    }

    /**
     * Test identical dynamic child rules retain wildcard collection paths.
     */
    public function testIdenticalDynamicChildRulesUseWildcardCollectionPaths(): void
    {
        $rules = DynamicRulesParentDataFixture::getValidationRules([
            'children' => [
                ['name' => 'Taylor'],
                ['name' => 'Taylor'],
            ],
        ]);

        $this->assertSame(['in:Taylor'], $rules['children.*.name']);
        $this->assertArrayNotHasKey('children.0.name', $rules);
        $this->assertArrayNotHasKey('children.1.name', $rules);
    }

    /**
     * Test divergent dynamic child rules retain an empty wildcard identity marker.
     */
    public function testDivergentDynamicChildRulesUseConcreteCollectionPaths(): void
    {
        $rules = DynamicRulesParentDataFixture::getValidationRules([
            'children' => [
                ['name' => 'Taylor'],
                ['name' => 'Swift'],
            ],
        ]);

        $this->assertSame(['in:Taylor'], $rules['children.0.name']);
        $this->assertSame(['in:Swift'], $rules['children.1.name']);
        $this->assertSame([], $rules['children.*.name']);
    }

    /**
     * Test nested dynamic rule graphs compare every outer collection item.
     */
    public function testNestedDynamicRuleGraphsRecompileOuterCollectionPaths(): void
    {
        $rules = NestedDynamicRulesParentDataFixture::getValidationRules([
            'items' => [
                ['child' => ['name' => 'Taylor']],
                ['child' => ['name' => 'Swift']],
            ],
        ]);

        $this->assertSame(['in:Taylor'], $rules['items.0.child.name']);
        $this->assertSame(['in:Swift'], $rules['items.1.child.name']);
        $this->assertSame([], $rules['items.*.child.name']);
    }

    /**
     * Test nested structural markers retain one complete wildcard identity.
     */
    public function testNestedDynamicCollectionsDoNotRetainPartialWildcardRules(): void
    {
        $rules = NestedDynamicCollectionParentDataFixture::getValidationRules([
            'groups' => [
                ['children' => [
                    ['name' => 'Taylor'],
                    ['name' => 'Swift'],
                ]],
                ['children' => [
                    ['name' => 'Abigail'],
                    ['name' => 'Joseph'],
                ]],
            ],
        ]);

        $this->assertSame(['in:Taylor'], $rules['groups.0.children.0.name']);
        $this->assertSame(['in:Swift'], $rules['groups.0.children.1.name']);
        $this->assertSame(['in:Abigail'], $rules['groups.1.children.0.name']);
        $this->assertSame(['in:Joseph'], $rules['groups.1.children.1.name']);
        $this->assertSame([], $rules['groups.*.children.*.name']);
        $this->assertArrayNotHasKey('groups.*.children.0.name', $rules);
        $this->assertArrayNotHasKey('groups.*.children.1.name', $rules);

        $nameRules = array_filter(
            $rules,
            static fn (string $key): bool => str_ends_with($key, '.name'),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertSame('groups.*.children.*.name', array_key_first($nameRules));
    }

    /**
     * Test nested dynamic collection sizes retain exactly their supplied values.
     *
     * @param array<array-key, list<string>> $groupChildNames
     */
    #[DataProvider('nestedDynamicCollectionSizeCases')]
    public function testNestedDynamicCollectionSizesCompileAuthoritatively(
        array $groupChildNames,
    ): void {
        $this->app->make(ValidationFactory::class)->excludeUnvalidatedArrayKeys();
        $groups = [];

        foreach ($groupChildNames as $key => $childNames) {
            $groups[$key] = [
                'children' => array_map(
                    static fn (string $name): array => ['name' => $name],
                    $childNames,
                ),
            ];
        }

        $validated = NestedDynamicCollectionParentDataFixture::validate([
            'groups' => $groups,
        ]);
        $data = NestedDynamicCollectionParentDataFixture::validateAndCreate([
            'groups' => $groups,
        ]);
        $createdNames = [];

        foreach ($data->groups as $key => $group) {
            $createdNames[$key] = array_map(
                static fn (DynamicRulesChildDataFixture $child): string => $child->name,
                $group->children,
            );
        }

        $this->assertSame(array_keys($groups), array_keys($validated['groups']));
        $this->assertSame(array_keys($groups), array_keys($data->groups));
        $this->assertSame($groupChildNames, $createdNames);

        if (array_is_list($groups)) {
            $this->assertTrue(array_is_list($validated['groups']));
        }
    }

    /**
     * Get nested dynamic collection size cases.
     *
     * @return array<string, array{array<array-key, list<string>>}>
     */
    public static function nestedDynamicCollectionSizeCases(): array
    {
        return [
            'later group has more children' => [[
                ['Taylor'],
                ['Abigail', 'Joseph'],
            ]],
            'later group has fewer children' => [[
                ['Taylor', 'Swift'],
                ['Abigail'],
            ]],
            'string keys retain order' => [[
                'primary' => ['Taylor'],
                'secondary' => ['Abigail', 'Joseph'],
            ]],
            'numeric gaps remain gaps' => [[
                1 => ['Taylor'],
                3 => ['Abigail', 'Joseph'],
            ]],
        ];
    }

    /**
     * Test nested distinct rules compare across every wildcard level.
     */
    public function testNestedDistinctRulesUseGlobalWildcardIdentity(): void
    {
        try {
            NestedDistinctParentDataFixture::validateAndCreate([
                'groups' => [
                    ['children' => [
                        ['name' => 'shared'],
                        ['name' => 'primary'],
                    ]],
                    ['children' => [
                        ['name' => 'shared'],
                        ['name' => 'secondary'],
                    ]],
                ],
            ]);
            $this->fail('Expected duplicate values across groups to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('groups.0.children.0.name', $exception->errors());
            $this->assertArrayHasKey('groups.1.children.0.name', $exception->errors());
        }
    }

    /**
     * Test unique nested values pass regardless of wildcard compilation depth.
     */
    public function testNestedDistinctRulesAcceptValuesUniqueAcrossTheGraph(): void
    {
        $data = NestedDistinctParentDataFixture::validateAndCreate([
            'groups' => [
                ['children' => [
                    ['name' => 'first'],
                    ['name' => 'second'],
                ]],
                ['children' => [
                    ['name' => 'third'],
                    ['name' => 'fourth'],
                ]],
            ],
        ]);

        $this->assertSame('first', $data->groups[0]->children[0]->name);
        $this->assertSame('third', $data->groups[1]->children[0]->name);
    }

    /**
     * Test partial wildcard and exact contributors retain one global identity.
     */
    public function testNestedDistinctRulesCombinePartialAndExactContributors(): void
    {
        $payload = [
            'groups' => [
                ['children' => [
                    ['name' => 'shared', 'category' => 'same'],
                    ['name' => 'second', 'category' => 'same'],
                ]],
                ['children' => [
                    ['name' => 'shared', 'category' => 'first'],
                    ['name' => 'fourth', 'category' => 'second'],
                ]],
            ],
        ];
        $rules = MixedNestedDistinctParentDataFixture::getValidationRules($payload);
        $nameRules = array_filter(
            $rules,
            static fn (string $key): bool => str_ends_with($key, '.name'),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertSame('groups.*.children.*.name', array_key_first($nameRules));
        $this->assertSame([], $rules['groups.*.children.*.name']);
        $this->assertArrayHasKey('groups.0.children.*.name', $rules);
        $this->assertArrayHasKey('groups.1.children.0.name', $rules);
        $this->assertArrayHasKey('groups.1.children.1.name', $rules);

        try {
            MixedNestedDistinctParentDataFixture::validateAndCreate($payload);
            $this->fail('Expected duplicate values across compilation modes to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('groups.0.children.0.name', $exception->errors());
            $this->assertArrayHasKey('groups.1.children.0.name', $exception->errors());
        }
    }

    /**
     * Test nested distinct rules still reject duplicate siblings.
     */
    public function testNestedDistinctRulesRejectDuplicateSiblings(): void
    {
        try {
            NestedDistinctParentDataFixture::validateAndCreate([
                'groups' => [
                    ['children' => [
                        ['name' => 'duplicate'],
                        ['name' => 'duplicate'],
                    ]],
                    ['children' => [
                        ['name' => 'primary'],
                        ['name' => 'secondary'],
                    ]],
                ],
            ]);
            $this->fail('Expected duplicate siblings to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('groups.0.children.0.name', $exception->errors());
            $this->assertArrayHasKey('groups.0.children.1.name', $exception->errors());
            $this->assertArrayNotHasKey('groups.1.children.0.name', $exception->errors());
            $this->assertArrayNotHasKey('groups.1.children.1.name', $exception->errors());
        }
    }

    /**
     * Test finished items cannot narrow a nested distinct identity.
     */
    #[DataProvider('finishedValueOrderCases')]
    public function testFinishedNestedDistinctItemsRejectNarrowerIdentity(
        bool $finishedFirst,
    ): void {
        $finished = new NestedDistinctChildDataFixture('finished');
        $children = $finishedFirst
            ? [$finished, ['name' => 'raw']]
            : [['name' => 'raw'], $finished];

        $this->expectException(CannotBuildValidationRule::class);
        $this->expectExceptionMessageIsOrContains(
            'Cannot build the distinct rule for [groups.*.children.*.name]',
        );

        NestedDistinctParentDataFixture::getValidationRules([
            'groups' => [['children' => $children]],
        ]);
    }

    /**
     * Test finished properties cannot narrow a nested distinct identity.
     */
    #[DataProvider('finishedValueOrderCases')]
    public function testFinishedNestedDistinctPropertiesRejectNarrowerIdentity(
        bool $finishedFirst,
    ): void {
        $finished = new NestedDistinctChildDataFixture('duplicate');
        $items = $finishedFirst
            ? [['child' => $finished], ['child' => ['name' => 'duplicate']]]
            : [['child' => ['name' => 'duplicate']], ['child' => $finished]];

        $this->expectException(CannotBuildValidationRule::class);
        $this->expectExceptionMessageIsOrContains(
            'Cannot build the distinct rule for [items.*.child.name]',
        );

        FinishedDistinctPropertyParentDataFixture::getValidationRules(['items' => $items]);
    }

    /**
     * Test finished containers cannot narrow a nested distinct identity.
     */
    #[DataProvider('finishedValueOrderCases')]
    public function testFinishedNestedDistinctContainersRejectNarrowerIdentity(
        bool $finishedFirst,
    ): void {
        $finished = new Collection([
            new NestedDistinctChildDataFixture('duplicate'),
        ]);
        $items = $finishedFirst
            ? [['children' => $finished], ['children' => [['name' => 'duplicate']]]]
            : [['children' => [['name' => 'duplicate']]], ['children' => $finished]];

        $this->expectException(CannotBuildValidationRule::class);
        $this->expectExceptionMessageIsOrContains(
            'Cannot build the distinct rule for [items.*.children.*.name]',
        );

        FinishedDistinctContainerParentDataFixture::getValidationRules(['items' => $items]);
    }

    /**
     * Test Data honors exclusion of unvalidated array keys.
     */
    public function testHonorsExcludedUnvalidatedArrayKeys(): void
    {
        $this->app->make(ValidationFactory::class)->excludeUnvalidatedArrayKeys();

        $validated = UnvalidatedArrayKeysDataFixture::validate([
            'meta' => ['known' => 'value', 'extra' => 'filtered'],
        ]);
        $validatedNull = UnvalidatedArrayKeysDataFixture::validate([
            'meta' => ['known' => null, 'extra' => 'filtered'],
        ]);

        $this->assertSame(['meta' => ['known' => 'value']], $validated);
        $this->assertSame(['meta' => ['known' => null]], $validatedNull);
    }

    /**
     * Test Data honors inclusion of unvalidated array keys.
     */
    public function testHonorsIncludedUnvalidatedArrayKeys(): void
    {
        $this->app->make(ValidationFactory::class)->includeUnvalidatedArrayKeys();
        $payload = [
            'meta' => ['known' => 'value', 'extra' => 'retained'],
        ];

        $this->assertSame($payload, UnvalidatedArrayKeysDataFixture::validate($payload));
        $this->assertSame(
            $payload['meta'],
            UnvalidatedArrayKeysDataFixture::validateAndCreate($payload)->meta,
        );
    }

    /**
     * Test ignored computed input retained with unvalidated array keys is never cast.
     */
    #[WithConfig('data.features.ignore_exception_when_trying_to_set_computed_property_value', true)]
    public function testIgnoredComputedInputRetainedWithUnvalidatedArrayKeysIsNotCast(): void
    {
        $this->app->make(ValidationFactory::class)->includeUnvalidatedArrayKeys();

        $data = ComputedParentDataFixture::validateAndCreate([
            'child' => ['first' => 'Taylor', 'full' => ['not', 'a', 'string']],
        ]);

        $this->assertSame('Taylor!', $data->child->full);
    }

    /**
     * Test uniform morph collections retain wildcard paths for equal dynamic rules.
     */
    public function testUniformMorphUsesSelectedClassForWildcardEligibility(): void
    {
        $rules = DynamicMorphParentDataFixture::getValidationRules([
            'children' => [
                ['type' => 'named', 'name' => 'Taylor'],
                ['type' => 'named', 'name' => 'Taylor'],
            ],
        ]);

        $this->assertSame(['in:Taylor'], $rules['children.*.name']);
        $this->assertArrayNotHasKey('children.0.name', $rules);
        $this->assertArrayNotHasKey('children.1.name', $rules);
    }

    /**
     * Test an operation rule hook retains wildcard paths when output is equal.
     */
    public function testIdenticalRuleHookOutputUsesWildcardCollectionPaths(): void
    {
        $rules = HookRulesParentDataFixture::factory()
            ->beforeRules(static fn (): null => null)
            ->getValidationRules([
                'children' => [
                    ['name' => 'Taylor'],
                    ['name' => 'Swift'],
                ],
            ]);

        $this->assertArrayHasKey('children.*.name', $rules);
        $this->assertArrayNotHasKey('children.0.name', $rules);
        $this->assertArrayNotHasKey('children.1.name', $rules);
    }

    /**
     * Test an operation rule hook recompiles concrete paths when output differs.
     */
    public function testDivergentRuleHookOutputUsesConcreteCollectionPaths(): void
    {
        $rules = HookRulesParentDataFixture::factory()
            ->beforeRules(static fn (DataProperty $property, ValidationPath $path, mixed $value): ?array => $property->name === 'name'
                ? ['in:' . $value]
                : null)
            ->getValidationRules([
                'children' => [
                    ['name' => 'Taylor'],
                    ['name' => 'Swift'],
                ],
            ]);

        $this->assertSame(['in:Taylor'], $rules['children.0.name']);
        $this->assertSame(['in:Swift'], $rules['children.1.name']);
        $this->assertSame([], $rules['children.*.name']);
    }

    /**
     * Test operation rule hooks do not pollute worker rule-graph metadata.
     */
    public function testRuleHooksDoNotPolluteDynamicRuleGraphMetadata(): void
    {
        $payload = [
            'children' => [
                ['name' => 'Taylor'],
                ['name' => 'Swift'],
            ],
        ];
        $hookRules = HookRulesParentDataFixture::factory()
            ->beforeRules(static fn (DataProperty $property, ValidationPath $path, mixed $value): ?array => $property->name === 'name'
                ? ['in:' . $value]
                : null)
            ->getValidationRules($payload);
        $plainRules = HookRulesParentDataFixture::getValidationRules($payload);

        $this->assertArrayHasKey('children.0.name', $hookRules);
        $this->assertArrayHasKey('children.1.name', $hookRules);
        $this->assertArrayHasKey('children.*.name', $plainRules);
        $this->assertArrayNotHasKey('children.0.name', $plainRules);
        $this->assertArrayNotHasKey('children.1.name', $plainRules);
    }

    /**
     * Test class rules compose with uniform and concrete child rules in declaration order.
     *
     * @param class-string<OverlappingClassRulesParentDataFixture> $class
     * @param list<string> $names
     * @param array<string, list<string>> $expectedRules
     */
    #[DataProvider('classRuleOverlapCases')]
    public function testClassRuleOverlapPreservesGeneratedBaselinesAndOrder(
        string $class,
        array $names,
        array $expectedRules,
    ): void {
        $rules = $class::factory()
            ->afterRules(static fn (
                array $rules,
                DataProperty $property,
                ValidationPath $path,
                mixed $value,
            ): array => $property->name === 'name'
                ? [...$rules, 'in:' . $value]
                : $rules)
            ->getValidationRules([
                'children' => array_map(
                    static fn (string $name): array => ['name' => $name],
                    $names,
                ),
            ]);
        $childRules = array_filter(
            $rules,
            static fn (string $key): bool => str_starts_with($key, 'children.'),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertSame($expectedRules, $childRules);
    }

    /**
     * Get class-rule overlap cases.
     *
     * @return array<string, array{class-string<OverlappingClassRulesParentDataFixture>, list<string>, array<string, list<string>>}>
     */
    public static function classRuleOverlapCases(): array
    {
        return [
            'replace exact then wildcard, uniform' => [
                ReplaceExactThenWildcardRulesParentDataFixture::class,
                ['Taylor', 'Taylor'],
                [
                    'children.0.name' => ['min:2'],
                    'children.*.name' => ['max:9'],
                ],
            ],
            'replace wildcard then exact, uniform' => [
                ReplaceWildcardThenExactRulesParentDataFixture::class,
                ['Taylor', 'Taylor'],
                [
                    'children.*.name' => ['max:9'],
                    'children.0.name' => ['min:2'],
                ],
            ],
            'merge exact then wildcard, uniform' => [
                MergeExactThenWildcardRulesParentDataFixture::class,
                ['Taylor', 'Taylor'],
                [
                    'children.0.name' => ['min:2'],
                    'children.*.name' => ['required', 'string', 'in:Taylor', 'max:9'],
                ],
            ],
            'merge wildcard then exact, uniform' => [
                MergeWildcardThenExactRulesParentDataFixture::class,
                ['Taylor', 'Taylor'],
                [
                    'children.*.name' => ['required', 'string', 'in:Taylor', 'max:9'],
                    'children.0.name' => ['min:2'],
                ],
            ],
            'replace exact then wildcard, divergent' => [
                ReplaceExactThenWildcardRulesParentDataFixture::class,
                ['Taylor', 'Swift'],
                [
                    'children.*.name' => [],
                    'children.0.name' => ['min:2', 'max:9'],
                    'children.1.name' => ['max:9'],
                ],
            ],
            'replace wildcard then exact, divergent' => [
                ReplaceWildcardThenExactRulesParentDataFixture::class,
                ['Taylor', 'Swift'],
                [
                    'children.*.name' => [],
                    'children.1.name' => ['max:9'],
                    'children.0.name' => ['min:2'],
                ],
            ],
            'merge exact then wildcard, divergent' => [
                MergeExactThenWildcardRulesParentDataFixture::class,
                ['Taylor', 'Swift'],
                [
                    'children.*.name' => [],
                    'children.0.name' => ['required', 'string', 'in:Taylor', 'min:2', 'max:9'],
                    'children.1.name' => ['required', 'string', 'in:Swift', 'max:9'],
                ],
            ],
            'merge wildcard then exact, divergent' => [
                MergeWildcardThenExactRulesParentDataFixture::class,
                ['Taylor', 'Swift'],
                [
                    'children.*.name' => [],
                    'children.1.name' => ['required', 'string', 'in:Swift', 'max:9'],
                    'children.0.name' => ['required', 'string', 'in:Taylor', 'min:2'],
                ],
            ],
        ];
    }

    /**
     * Test final fanned class rules own inferred presence suppression.
     */
    public function testFannedClassPresenceRulesSuppressInferredRequired(): void
    {
        $rules = MergePresenceWildcardRulesParentDataFixture::factory()
            ->afterRules(static fn (
                array $rules,
                DataProperty $property,
                ValidationPath $path,
                mixed $value,
            ): array => $property->name === 'name'
                ? [...$rules, 'in:' . $value]
                : $rules)
            ->getValidationRules([
                'children' => [
                    ['name' => 'Taylor'],
                    ['name' => 'Swift'],
                ],
                'enabled' => false,
            ]);

        $this->assertSame(
            ['string', 'in:Taylor', 'required_if:enabled,true', 'max:9'],
            $rules['children.0.name'],
        );
        $this->assertSame(
            ['string', 'in:Swift', 'required_if:enabled,true', 'max:9'],
            $rules['children.1.name'],
        );
        $this->assertSame([], $rules['children.*.name']);
    }

    /**
     * Test validation attributes supplement inference without duplicate presence rules.
     */
    public function testCompilesValidationAttributes(): void
    {
        $rules = AttributeValidatedDataFixture::getValidationRules([]);

        $this->assertSame(['required', 'string'], $rules['name']);
    }

    /**
     * Test a declared requiring rule drops only the sometimes rule inferred for an Optional property.
     */
    public function testDeclaredSometimesSurvivesARequiringRule(): void
    {
        $rules = SometimesRequiredWithDataFixture::getValidationRules([]);

        $this->assertSame(['string', 'required_with:other'], $rules['inferred']);
        $this->assertSame(['string', 'sometimes', 'required_with:other'], $rules['declared']);
    }

    /**
     * Test class-owned rules replace generated rules by default.
     */
    public function testClassRulesReplaceGeneratedRules(): void
    {
        $rules = ClassRulesValidatedDataFixture::getValidationRules(['name' => 'value']);

        $this->assertSame(['min:3'], $rules['name']);
    }

    /**
     * Test merged requiring rules replace only the inferred requirement.
     */
    public function testMergedRequiringRulesSuppressOnlyInferredRequired(): void
    {
        $rules = MergedRequiringRulesDataFixture::getValidationRules([
            'enabled' => false,
        ]);

        $this->assertSame(
            ['string', 'required_if:enabled,true', 'max:10'],
            $rules['value'],
        );
    }

    /**
     * Test a merged present rule replaces only the inferred requirement.
     */
    public function testMergedPresentRuleSuppressesOnlyInferredRequired(): void
    {
        $rules = MergedPresentRuleDataFixture::getValidationRules([]);

        $this->assertSame(['string', 'present'], $rules['value']);
    }

    /**
     * Test merged class rules never remove an explicit requiring attribute.
     */
    public function testMergedRulesPreserveExplicitRequiringAttributes(): void
    {
        try {
            ExplicitAndMergedRequiringRulesDataFixture::validate([
                'enabled' => false,
            ]);
            $this->fail('Expected the explicit required attribute to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('value', $exception->errors());
        }
    }

    /**
     * Test validation-only mode authorizes Request sources.
     */
    public function testValidateAuthorizesRequestSources(): void
    {
        $this->expectException(AuthorizationException::class);

        UnauthorizedValidatedDataFixture::validate(
            Request::create('/', 'POST', ['id' => 1]),
        );
    }

    /**
     * Test authorization responses retain their details before a direct factory exit.
     */
    public function testAuthorizationResponseRunsBeforeDirectFactoryExit(): void
    {
        DeniedDirectFactoryDataFixture::$factoryCalls = 0;

        try {
            DeniedDirectFactoryDataFixture::from(
                Request::create('/', 'POST', ['id' => 1]),
            );
            $this->fail('Expected authorization to fail.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Denied by policy.', $exception->getMessage());
            $this->assertSame('policy-code', $exception->getCode());
            $this->assertSame(403, $exception->status());
            $this->assertSame(0, DeniedDirectFactoryDataFixture::$factoryCalls);
        }
    }

    /**
     * Test validation-only APIs bypass direct-returning named factories.
     */
    public function testValidationOnlyModeBypassesNamedFactories(): void
    {
        try {
            DirectFactoryValidatedDataFixture::validate(['id' => 'invalid']);
            $this->fail('Expected raw payload validation to fail.');
        } catch (ValidationException) {
        }

        $data = DirectFactoryValidatedDataFixture::validateAndCreate(['id' => 'invalid']);

        $this->assertSame(99, $data->id);
    }

    /**
     * Test nested input Fill cannot read fails validation at its own path instead of creation.
     */
    public function testUnreadableNestedInputFailsValidationAtItsPath(): void
    {
        $valid = ['child' => ['name' => null], 'children' => []];

        foreach ([
            ['child', [...$valid, 'child' => 'text']],
            ['child', [...$valid, 'child' => '']],
            // Blank strings skip non-implicit rules, so the nullable and Optional objects need their own rejection.
            ['nullableChild', [...$valid, 'nullableChild' => '']],
            ['optionalChild', [...$valid, 'optionalChild' => ' ']],
            ['children', [...$valid, 'children' => '']],
            // The item class has no required property, so only the item's own shape rules reject it.
            ['children.0', [...$valid, 'children' => ['text']]],
            ['children.0', [...$valid, 'children' => ['']]],
            ['children.0', [...$valid, 'children' => [' ']]],
        ] as [$errorKey, $payload]) {
            try {
                UnreadableParentDataFixture::validate($payload);
                $this->fail("Expected [{$errorKey}] to fail validation.");
            } catch (ValidationException $exception) {
                $this->assertSame([$errorKey], array_keys($exception->errors()));
            }
        }

        $this->assertSame(
            ['required', 'array'],
            UnreadableParentDataFixture::getValidationRules([...$valid, 'children' => ['text']])['children.0'],
        );
        $this->assertSame(
            ['required', 'nullable', 'array'],
            UnreadableParentDataFixture::getValidationRules([...$valid, 'nullableChild' => ''])['nullableChild'],
        );
        $this->assertSame(
            ['nullable', 'array'],
            UnreadableParentDataFixture::getValidationRules([...$valid, 'nullableChild' => null])['nullableChild'],
        );
    }

    /**
     * Test unreadable root collection items and nested input supplied by a hook fail validation too.
     */
    public function testUnreadableCollectionItemsAndHookInputFailValidation(): void
    {
        foreach (['text', '', ' '] as $item) {
            try {
                OptionalNameDataFixture::factory()->alwaysValidate()->collect([$item]);
                $this->fail("Expected the unreadable item [{$item}] to fail validation.");
            } catch (ValidationException $exception) {
                $this->assertSame([0], array_keys($exception->errors()));
            }
        }

        try {
            UnreadableParentDataFixture::factory()
                ->alwaysValidate()
                ->beforeValidation(static fn (array $payload): array => [...$payload, 'child' => 'text'])
                ->from(['child' => ['name' => 'Taylor'], 'children' => []]);
            $this->fail('Expected the hook-supplied input to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['child'], array_keys($exception->errors()));
        }
    }

    /**
     * Test root input nothing can read still fails creation.
     */
    public function testUnreadableRootInputStillFailsCreation(): void
    {
        $this->expectException(CannotCreateData::class);

        UnreadableParentDataFixture::factory()->alwaysValidate()->from(42);
    }

    /**
     * Test a morph that valid input leaves unresolved still cannot be created.
     */
    public function testUnresolvedMorphThatPassesValidationIsNotCreated(): void
    {
        $this->expectException(CannotCreateAbstractClass::class);

        NullableMorphBaseDataFixture::validateAndCreate([]);
    }

    /**
     * Test rule introspection retains its upstream array-only payload contract.
     */
    public function testRuleIntrospectionAcceptsOnlyArrays(): void
    {
        $parameter = (new ReflectionMethod(
            ValidatedDataFixture::class,
            'getValidationRules',
        ))->getParameters()[0];

        $this->assertSame('array', (string) $parameter->getType());
    }

    /**
     * Test rule introspection exits before unrelated lifecycle declarations.
     */
    public function testRuleIntrospectionDoesNotResolveMessagesOrAttributes(): void
    {
        RuleIntrospectionLifecycleDataFixture::$rulesCalls = 0;
        RuleIntrospectionLifecycleDataFixture::$messagesCalls = 0;
        RuleIntrospectionLifecycleDataFixture::$attributesCalls = 0;

        $rules = RuleIntrospectionLifecycleDataFixture::getValidationRules([]);

        $this->assertSame(['required'], $rules['value']);
        $this->assertSame(1, RuleIntrospectionLifecycleDataFixture::$rulesCalls);
        $this->assertSame(0, RuleIntrospectionLifecycleDataFixture::$messagesCalls);
        $this->assertSame(0, RuleIntrospectionLifecycleDataFixture::$attributesCalls);
    }

    /**
     * Test finished nested Data values own and preserve their validation path.
     */
    public function testFinishedNestedDataSkipsDeclaredRulesAndRetainsIdentity(): void
    {
        $child = new FinishedValidatedChildDataFixture('x');
        $parent = FinishedValidatedParentDataFixture::validateAndCreate([
            'child' => $child,
        ]);

        $this->assertSame($child, $parent->child);
    }

    /**
     * Test a finished value's own property still applies its declared and class exclusions.
     *
     * @param class-string<FinishedExcludedAttributeDataFixture|FinishedExcludedClassRuleDataFixture|FinishedExcludedRuleDataFixture> $class
     */
    #[DataProvider('finishedValueExclusionCases')]
    public function testFinishedValuesApplyTheirPropertysExclusions(string $class): void
    {
        $child = new FinishedValidatedChildDataFixture('x');

        $this->assertNull($class::validateAndCreate(['skip' => true, 'child' => $child])->child);
        $this->assertSame($child, $class::validateAndCreate(['skip' => false, 'child' => $child])->child);
    }

    /**
     * Get the forms that declare an exclusion for a finished value's property.
     *
     * @return array<string, array{class-string<Data>}>
     */
    public static function finishedValueExclusionCases(): array
    {
        return [
            'attribute' => [FinishedExcludedAttributeDataFixture::class],
            'rule attribute' => [FinishedExcludedRuleDataFixture::class],
            'class rule' => [FinishedExcludedClassRuleDataFixture::class],
        ];
    }

    /**
     * Test a custom rule declared on a finished value's property receives the object.
     */
    public function testFinishedValuesApplyTheirPropertysCustomRules(): void
    {
        $child = new FinishedValidatedChildDataFixture('allowed');

        $this->assertSame($child, FinishedCustomRuleDataFixture::validateAndCreate(['child' => $child])->child);

        try {
            FinishedCustomRuleDataFixture::validateAndCreate(['child' => new FinishedValidatedChildDataFixture('blocked')]);
            $this->fail('Expected the custom rule to reject the finished value.');
        } catch (ValidationException $exception) {
            $this->assertSame(['child' => ['The child is blocked.']], $exception->errors());
        }
    }

    /**
     * Test a rule rejecting a finished value or item uses the class's custom message for it.
     *
     * @param class-string<Data> $class
     * @param array<string, mixed> $payload
     * @param array<string, list<string>> $errors
     */
    #[DataProvider('finishedValueMessageCases')]
    public function testFinishedValuesUseTheirCustomMessages(string $class, array $payload, array $errors): void
    {
        try {
            $class::validateAndCreate($payload);
            $this->fail('Expected the finished value to be prohibited.');
        } catch (ValidationException $exception) {
            $this->assertSame($errors, $exception->errors());
        }
    }

    /**
     * Get the finished values whose own rule has a custom message.
     *
     * @return array<string, array{class-string<Data>, array<string, mixed>, array<string, list<string>>}>
     */
    public static function finishedValueMessageCases(): array
    {
        $finished = new FinishedValidatedChildDataFixture('finished');
        $children = [$finished, ['name' => 'raw']];

        return [
            'property' => [
                FinishedProhibitedMessageDataFixture::class,
                ['child' => $finished],
                ['child' => ['A child cannot be given.']],
            ],
            'exact item' => [
                FinishedProhibitedItemMessageDataFixture::class,
                ['children' => $children],
                ['children.0' => ['The first child cannot be given.']],
            ],
            'wildcard items' => [
                FinishedProhibitedItemsMessageDataFixture::class,
                ['children' => $children],
                ['children.0' => ['No child can be given.'], 'children.1' => ['No child can be given.']],
            ],
        ];
    }

    /**
     * Test a field-only message for a mapped nested field follows the field's input name.
     */
    public function testFieldOnlyMessagesFollowMappedNestedNames(): void
    {
        try {
            MappedFieldMessageParentDataFixture::validateAndCreate(['child' => ['age' => 1]]);
            $this->fail('Expected the mapped nested field to be required.');
        } catch (ValidationException $exception) {
            $this->assertSame(['child.full_name' => ['Supply a name.']], $exception->errors());
        }
    }

    /**
     * Test finished items apply parent rules targeting the items themselves beside raw siblings.
     *
     * @param class-string<FinishedExcludedItemDataFixture|FinishedExcludedItemsDataFixture> $class
     * @param list<int> $remainingKeys
     */
    #[DataProvider('finishedItemExclusionCases')]
    public function testFinishedItemsApplyRulesTargetingThemselves(string $class, array $remainingKeys): void
    {
        $finished = new FinishedValidatedChildDataFixture('finished');
        $children = [$finished, ['name' => 'raw']];

        $excluded = $class::validateAndCreate(['skip' => true, 'children' => $children]);
        $retained = $class::validateAndCreate(['skip' => false, 'children' => $children]);

        $this->assertSame($remainingKeys, array_keys($excluded->children));
        $this->assertSame($finished, $retained->children[0]);
        $this->assertSame('raw', $retained->children[1]->name);
    }

    /**
     * Get the parent rules targeting finished items, with the item keys their exclusion leaves.
     *
     * @return array<string, array{class-string<Data>, list<int>}>
     */
    public static function finishedItemExclusionCases(): array
    {
        return [
            'exact item' => [FinishedExcludedItemDataFixture::class, [1]],
            'wildcard items' => [FinishedExcludedItemsDataFixture::class, []],
        ];
    }

    /**
     * Test finished nested properties latch every enclosing collection.
     */
    #[DataProvider('finishedValueOrderCases')]
    public function testFinishedNestedPropertiesLatchEnclosingCollections(
        bool $finishedFirst,
    ): void {
        $finished = new FinishedValidatedChildDataFixture('finished');
        $items = $finishedFirst
            ? [['child' => $finished], ['child' => ['name' => 'raw']]]
            : [['child' => ['name' => 'raw']], ['child' => $finished]];
        $rawIndex = $finishedFirst ? 1 : 0;
        $finishedIndex = $finishedFirst ? 0 : 1;
        $rules = FinishedNestedParentDataFixture::getValidationRules([
            'items' => $items,
        ]);
        $data = FinishedNestedParentDataFixture::validateAndCreate([
            'items' => $items,
        ]);

        $this->assertSame(['min:3'], $rules["items.{$rawIndex}.child.name"]);
        $this->assertArrayNotHasKey("items.{$finishedIndex}.child.name", $rules);
        $this->assertArrayNotHasKey('items.*.child.name', $rules);
        $this->assertSame($finished, $data->items[$finishedIndex]->child);
        $this->assertSame('raw', $data->items[$rawIndex]->child->name);
    }

    /**
     * Test finished data collections latch every enclosing collection.
     */
    #[DataProvider('finishedValueOrderCases')]
    public function testFinishedDataCollectionsLatchEnclosingCollections(
        bool $finishedFirst,
    ): void {
        $finished = new DataCollection(FinishedValidatedChildDataFixture::class, [
            'finished' => new FinishedValidatedChildDataFixture('finished'),
        ]);
        $items = $finishedFirst
            ? [['children' => $finished], ['children' => ['raw' => ['name' => 'raw']]]]
            : [['children' => ['raw' => ['name' => 'raw']]], ['children' => $finished]];
        $rawIndex = $finishedFirst ? 1 : 0;
        $finishedIndex = $finishedFirst ? 0 : 1;
        $payload = ['items' => $items];
        $rules = FinishedDataCollectionParentDataFixture::getValidationRules($payload);
        $data = FinishedDataCollectionParentDataFixture::validateAndCreate($payload);

        $this->assertSame(['min:3'], $rules["items.{$rawIndex}.children.*.name"]);
        $this->assertArrayNotHasKey("items.{$finishedIndex}.children.*.name", $rules);
        $this->assertArrayNotHasKey('items.*.children.*.name', $rules);
        $this->assertSame($finished, $data->items[$finishedIndex]->children);
        $this->assertSame('raw', $data->items[$rawIndex]->children->items()['raw']->name);
    }

    /**
     * Test finished native collections latch every enclosing collection.
     */
    #[DataProvider('finishedValueOrderCases')]
    public function testFinishedNativeCollectionsLatchEnclosingCollections(
        bool $finishedFirst,
    ): void {
        $finished = new Collection([
            'finished' => new FinishedValidatedChildDataFixture('finished'),
        ]);
        $items = $finishedFirst
            ? [['children' => $finished], ['children' => ['raw' => ['name' => 'raw']]]]
            : [['children' => ['raw' => ['name' => 'raw']]], ['children' => $finished]];
        $rawIndex = $finishedFirst ? 1 : 0;
        $finishedIndex = $finishedFirst ? 0 : 1;
        $payload = ['items' => $items];
        $rules = FinishedNativeCollectionParentDataFixture::getValidationRules($payload);
        $data = FinishedNativeCollectionParentDataFixture::validateAndCreate($payload);

        $this->assertSame(['min:3'], $rules["items.{$rawIndex}.children.*.name"]);
        $this->assertArrayNotHasKey("items.{$finishedIndex}.children.*.name", $rules);
        $this->assertArrayNotHasKey('items.*.children.*.name', $rules);
        $this->assertSame($finished, $data->items[$finishedIndex]->children);
        $this->assertSame('raw', $data->items[$rawIndex]->children->get('raw')->name);
    }

    /**
     * Test finished nested collection items latch every enclosing collection.
     */
    #[DataProvider('finishedValueOrderCases')]
    public function testFinishedNestedCollectionItemsLatchEnclosingCollections(
        bool $finishedFirst,
    ): void {
        $finished = new FinishedValidatedChildDataFixture('finished');
        $children = $finishedFirst
            ? [$finished, ['name' => 'raw']]
            : [['name' => 'raw'], $finished];
        $rawIndex = $finishedFirst ? 1 : 0;
        $finishedIndex = $finishedFirst ? 0 : 1;
        $payload = [
            'groups' => [['children' => $children]],
        ];
        $rules = FinishedNestedCollectionParentDataFixture::getValidationRules($payload);
        $data = FinishedNestedCollectionParentDataFixture::validateAndCreate($payload);

        $this->assertSame(['min:3'], $rules["groups.0.children.{$rawIndex}.name"]);
        $this->assertArrayNotHasKey("groups.0.children.{$finishedIndex}.name", $rules);
        $this->assertArrayNotHasKey('groups.*.children.*.name', $rules);
        $this->assertArrayNotHasKey("groups.*.children.{$rawIndex}.name", $rules);
        $this->assertSame($finished, $data->groups[0]->children[$finishedIndex]);
        $this->assertSame('raw', $data->groups[0]->children[$rawIndex]->name);
    }

    /**
     * Get finished-value order cases.
     *
     * @return array<string, array{bool}>
     */
    public static function finishedValueOrderCases(): array
    {
        return [
            'finished first' => [true],
            'finished last' => [false],
        ];
    }

    /**
     * Test a direct factory exit cannot hide a raw collection sibling.
     */
    public function testDirectFactoryFinishedValueDoesNotHideRawSibling(): void
    {
        try {
            DirectFinishedParentDataFixture::validateAndCreate([
                'children' => [
                    'finished',
                    ['name' => 'invalid'],
                ],
            ]);
            $this->fail('Expected the raw sibling to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('children.1.name', $exception->errors());
            $this->assertArrayNotHasKey('children.0.name', $exception->errors());
        }
    }

    /**
     * Test a strict root rejects input outside its compiled schema.
     */
    public function testFailOnUnknownFieldsRejectsUnknownRootInput(): void
    {
        try {
            StrictValidatedDataFixture::validateAndCreate([
                'name' => 'Taylor',
                'role' => 'admin',
            ]);
            $this->fail('Expected unknown-field validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }
    }

    /**
     * Test strictness applies at the selected nested data class.
     */
    public function testFailOnUnknownFieldsRejectsUnknownNestedInput(): void
    {
        try {
            NestedStrictParentDataFixture::validateAndCreate([
                'child' => [
                    'name' => 'Taylor',
                    'role' => 'admin',
                ],
            ]);
            $this->fail('Expected nested unknown-field validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('child.role', $exception->errors());
        }
    }

    /**
     * Test a strict parent keeps ordinary nested Data structured.
     */
    public function testStrictParentDoesNotTreatNestedDataAsAnOpaqueSubtree(): void
    {
        try {
            StrictNestedParentDataFixture::validateAndCreate([
                'child' => [
                    'name' => 'Taylor',
                    'role' => 'admin',
                ],
            ]);
            $this->fail('Expected nested unknown-field validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('child.role', $exception->errors());
        }
    }

    /**
     * Test direct Request query input stays outside the unknown-field boundary.
     */
    public function testFailOnUnknownFieldsIgnoresDirectRequestQueryInput(): void
    {
        $request = Request::create('/?tracking=campaign', 'POST', [
            'name' => 'Taylor',
        ]);

        $data = StrictValidatedDataFixture::from($request);

        $this->assertSame('Taylor', $data->name);
    }

    /**
     * Test a nested strict array retains query values selected by its parent Request.
     */
    public function testFailOnUnknownFieldsChecksNestedArraysFromRequestQueryInput(): void
    {
        $request = Request::create(
            '/?child[name]=Taylor&child[role]=admin',
            'GET',
        );

        try {
            NestedStrictParentDataFixture::from($request);
            $this->fail('Expected nested query input to fail unknown-field validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('child.role', $exception->errors());
        }
    }

    /**
     * Test a custom request normalizer's single result is the unknown-field input, not the request body.
     */
    public function testFailOnUnknownFieldsChecksTheSourceACustomRequestNormalizerReturns(): void
    {
        $normalizer = new EnvelopeRequestNormalizer;

        $data = StrictValidatedDataFixture::factory()
            ->withNormalizers($normalizer)
            ->from(Request::create('/', 'POST', ['data' => ['name' => 'Taylor']]));

        $this->assertSame('Taylor', $data->name);
        $this->assertSame(1, $normalizer->calls);

        try {
            StrictValidatedDataFixture::factory()
                ->withNormalizers($normalizer)
                ->from(Request::create('/', 'POST', ['data' => ['name' => 'Taylor', 'role' => 'admin']]));
            $this->fail('Expected unknown normalized input to fail unknown-field validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['role'], array_keys($exception->errors()));
        }
    }

    /**
     * Test a strict morph subtype selected from its parent checks the request body but not the query string.
     */
    public function testFailOnUnknownFieldsAppliesToTheSelectedMorphSubtype(): void
    {
        $data = StrictMorphBaseDataFixture::from(Request::create('/?tracking=campaign', 'POST', [
            'type' => 'strict',
            'name' => 'Taylor',
        ]));

        $this->assertInstanceOf(StrictMorphChildDataFixture::class, $data);
        $this->assertSame('Taylor', $data->name);

        try {
            StrictMorphBaseDataFixture::from(Request::create('/?tracking=campaign', 'POST', [
                'type' => 'strict',
                'name' => 'Taylor',
                'role' => 'admin',
            ]));
            $this->fail('Expected the selected subtype to fail unknown-field validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['role'], array_keys($exception->errors()));
        }
    }

    /**
     * Test a strict parent checks caller input removed by its prepare hook.
     */
    public function testFailOnUnknownFieldsUsesInputBeforeTheCurrentNodePrepareHook(): void
    {
        try {
            StrictNestedParentDataFixture::factory()
                ->alwaysValidate()
                ->prepareData(static function (array $payload): array {
                    unset($payload['role']);

                    return $payload;
                })
                ->from([
                    'child' => ['name' => 'Taylor'],
                    'role' => 'admin',
                ]);
            $this->fail('Expected removed caller input to fail unknown-field validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }
    }

    /**
     * Test uniform collections accept wildcard-shaped exact and subtree auxiliaries.
     */
    public function testFailOnUnknownFieldsAcceptsUniformCollectionAuxiliaryPaths(): void
    {
        $data = AuxiliaryParentDataFixture::validateAndCreate([
            'items' => [
                [
                    'id' => 1,
                    'serverUser' => 'first',
                    'meta' => ['source' => 'import'],
                    'literal*' => 'one',
                ],
                [
                    'id' => 2,
                    'serverUser' => 'second',
                    'meta' => [],
                    'literal*' => 'two',
                    'note' => 'later item',
                ],
            ],
        ]);

        $this->assertSame('first', $data->items[0]->serverUser);
        $this->assertSame(['source' => 'import'], $data->items[0]->meta);
        $this->assertInstanceOf(Optional::class, $data->items[0]->note);
        $this->assertSame('two', $data->items[1]->literalStar);
        $this->assertSame('later item', $data->items[1]->note);
    }

    /**
     * Test contextual echoes are known input while server values remain authoritative.
     */
    public function testFailOnUnknownFieldsAcceptsContextualEchoesWithoutUsingThem(): void
    {
        config([
            'tests.data.server_user' => 42,
            'tests.data.context' => ['source' => 'server'],
        ]);

        $data = ContextualStrictDataFixture::validateAndCreate([
            'name' => 'Taylor',
            'server_user' => 7,
            'context' => ['source' => 'client'],
        ]);

        $this->assertSame(42, $data->serverUser);
        $this->assertSame(['source' => 'server'], $data->context);
    }

    /**
     * Test unstructured mixed values and declared arrays retain their contents.
     */
    public function testFailOnUnknownFieldsAllowsUnstructuredDeclaredValues(): void
    {
        $data = UnstructuredStrictDataFixture::validateAndCreate([
            'meta' => ['source' => ['name' => 'import']],
            'options' => ['one', 'two'],
        ]);

        $this->assertSame(['source' => ['name' => 'import']], $data->meta);
        $this->assertSame(['one', 'two'], $data->options);
    }

    /**
     * Test unknown-field checking uses rules added by the root Validator hook.
     */
    public function testFailOnUnknownFieldsUsesEffectiveValidatorRules(): void
    {
        $data = DynamicStrictValidatedDataFixture::validateAndCreate([
            'name' => 'Taylor',
            'nickname' => 'Tay',
        ]);

        $this->assertSame('Taylor', $data->name);
    }

    /**
     * Test factory validation hooks run once in their documented flow order.
     */
    public function testFactoryValidationHooksRunInFlowOrder(): void
    {
        $calls = [];

        $data = FactoryValidationHooksDataFixture::factory()
            ->alwaysValidate()
            ->beforeValidation(function (array $payload) use (&$calls): array {
                $calls[] = 'before-validation';
                $payload['value'] = 'prepared';

                return $payload;
            })
            ->beforeRules(function (
                DataProperty $property,
                ValidationPath $path,
                mixed $value,
            ) use (&$calls): array {
                $calls[] = 'before-rules';
                $this->assertSame('value', $property->name);
                $this->assertSame('value', $path->get());
                $this->assertSame('prepared', $value);

                return ['in:prepared'];
            })
            ->afterRules(function (array $rules) use (&$calls): array {
                $calls[] = 'after-rules';

                return [...$rules, 'string'];
            })
            ->withValidator(function (Validator $validator) use (&$calls): void {
                $calls[] = 'with-validator';
                $this->assertArrayHasKey('value', $validator->getRulesWithoutPlaceholders());
            })
            ->afterValidation(function (array $payload) use (&$calls): array {
                $calls[] = 'after-validation';
                $payload['value'] = 'validated';

                return $payload;
            })
            ->from(['value' => 'raw']);

        $this->assertSame('validated', $data->value);
        $this->assertSame([
            'before-validation',
            'before-rules',
            'after-rules',
            'with-validator',
            'after-validation',
        ], $calls);
    }

    /**
     * Test validation hooks receive and may supply undeclared input, including for a node they add.
     */
    public function testValidationHooksReceiveAndSupplyUndeclaredInput(): void
    {
        $received = null;

        try {
            ConditionalNameDataFixture::factory()
                ->alwaysValidate()
                ->beforeValidation(function (array $payload) use (&$received): array {
                    $received = $payload;

                    return [...$payload, 'mode' => 'admin'];
                })
                ->from(['locale' => 'en']);
            $this->fail('Expected the hook-supplied condition to require the name.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        $this->assertSame(['locale' => 'en'], $received);

        ContextParentDataFixture::$contexts = [];
        ContextParentDataFixture::factory()
            ->alwaysValidate()
            ->beforeValidation(static fn (array $payload): array => [
                ...$payload,
                'child' => ['kind' => 'primary', 'value' => 'a'],
            ])
            ->from([]);

        $this->assertSame(['kind' => 'primary', 'value' => 'a'], ContextParentDataFixture::$contexts['child'][0]);
    }

    /**
     * Test validation hooks can add a nested data value before rules are compiled.
     */
    public function testBeforeValidationReconcilesHookAddedNestedData(): void
    {
        try {
            HookReconciliationParentDataFixture::factory()
                ->alwaysValidate()
                ->beforeValidation(static fn (array $payload): array => [
                    ...$payload,
                    'child' => ['name' => 123],
                ])
                ->from([]);
            $this->fail('Expected the hook-added nested value to be validated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('child.name', $exception->errors());
        }

        $data = HookReconciliationParentDataFixture::factory()
            ->alwaysValidate()
            ->beforeValidation(static fn (array $payload): array => [
                ...$payload,
                'child' => ['name' => 'Taylor'],
            ])
            ->from([]);

        $this->assertInstanceOf(HookReconciliationChildDataFixture::class, $data->child);
        $this->assertSame('Taylor', $data->child->name);
    }

    /**
     * Test validation hooks reselect scalar wire keys and canonical absence paths.
     */
    public function testBeforeValidationReconcilesScalarMappingsAndRemoval(): void
    {
        $data = HookMappedScalarDataFixture::factory()
            ->alwaysValidate()
            ->beforeValidation(static fn (): array => [
                'email_address' => 'new@example.com',
            ])
            ->from(['email' => 'old@example.com']);

        $this->assertSame('new@example.com', $data->email);

        try {
            HookMappedScalarDataFixture::factory()
                ->alwaysValidate()
                ->beforeValidation(static fn (): array => [])
                ->from(['email' => 'old@example.com']);
            $this->fail('Expected the removed mapped property to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email_address', $exception->errors());
            $this->assertArrayNotHasKey('email', $exception->errors());
        }
    }

    /**
     * Test validation hooks do not replace scalar mapped ancestors with arrays.
     */
    public function testBeforeValidationDoesNotReplaceScalarMappedAncestorsWithArrays(): void
    {
        $payload = HookMappedUnvalidatedDataFixture::factory()
            ->beforeValidation(static fn (array $payload): array => [
                ...$payload,
                'nested' => 'scalar',
            ])
            ->validate([
                'id' => 1,
                'nested' => ['value' => 'original'],
            ]);

        $this->assertSame(['id' => 1], $payload);
    }

    public function testRestoresMappedUnvalidatedValuesIntoUnvalidatedContainers(): void
    {
        $this->assertSame(
            ['id' => 1, 'nested' => ['value' => 'kept']],
            HookMappedUnvalidatedDataFixture::validate(['id' => 1, 'nested' => ['value' => 'kept']]),
        );
    }

    public function testUnvalidatedConstructorInputsArePreserved(): void
    {
        $data = ValidatedConstructorInputData::validateAndCreate([
            'prefix' => 'p',
            'options' => ['a' => 1, 'nested' => ['b' => 2]],
            'secret' => 's',
        ]);

        $this->assertSame(
            ['prefix' => 'p', 'options' => ['a' => 1, 'nested' => ['b' => 2]], 'secret' => 's'],
            $data->received,
        );
    }

    public function testRulesGovernConstructorInputs(): void
    {
        $data = GovernedConstructorInputData::validateAndCreate([
            'prefix' => 'p',
            'options' => ['a' => 1, 'b' => 2],
            'secret' => 's',
        ]);

        $this->assertSame(['prefix' => 'p', 'options' => ['a' => 1], 'secret' => 'none'], $data->received);
    }

    public function testParentRulesGovernNestedConstructorInputsAndExclusions(): void
    {
        $data = ConstructorInputParentData::validateAndCreate([
            'items' => [['prefix' => 'a', 'secret' => 'x']],
            'skip' => true,
            'child' => ['prefix' => 'c', 'secret' => 'y', 'note' => 'unvalidated'],
        ]);

        $this->assertSame(['prefix' => 'a', 'options' => [], 'secret' => 'none'], $data->items[0]->received);
        $this->assertNull($data->child);
    }

    public function testValidationHooksEditConstructorInputs(): void
    {
        $data = ValidatedConstructorInputData::factory()
            ->alwaysValidate()
            ->beforeValidation(static fn (array $payload): array => [...$payload, 'prefix' => 'before'])
            ->afterValidation(static fn (array $payload): array => [...$payload, 'secret' => 'after'])
            ->from(['prefix' => 'original', 'secret' => 'original']);

        $this->assertSame(['prefix' => 'before', 'options' => [], 'secret' => 'after'], $data->received);
    }

    /**
     * Test validation hooks can replace filled data with a named-factory value.
     */
    public function testBeforeValidationReconcilesStructuredValuesThroughNamedFactories(): void
    {
        HookFactoryChildDataFixture::$factoryCalls = 0;

        $data = HookFactoryParentDataFixture::factory()
            ->alwaysValidate()
            ->beforeValidation(static fn (array $payload): array => [
                ...$payload,
                'child' => 'replacement',
            ])
            ->from([
                'child' => ['name' => 'original'],
            ]);

        $this->assertSame('factory:replacement', $data->child->name);
        $this->assertSame(1, HookFactoryChildDataFixture::$factoryCalls);
    }

    /**
     * Test reconciliation does not replay earlier user transforms or sibling factories.
     */
    public function testBeforeValidationPreservesEarlierHookAndFactoryResults(): void
    {
        HookFactoryChildDataFixture::$factoryCalls = 0;
        $prepareCalls = 0;
        $normalizer = new HookCountingNormalizer;

        $data = HookTransformParentDataFixture::factory()
            ->alwaysValidate()
            ->withNormalizers($normalizer)
            ->prepareData(function (array $payload) use (&$prepareCalls): array {
                ++$prepareCalls;

                return $payload;
            })
            ->beforeValidation(static function (array $payload): array {
                $payload['changed']['name'] = 'updated';

                return $payload;
            })
            ->from([
                'changed' => ['name' => 'original'],
                'sibling' => 'stable',
            ]);

        $this->assertSame('updated', $data->changed->name);
        $this->assertSame('factory:stable', $data->sibling->name);
        $this->assertSame(2, $prepareCalls);
        $this->assertSame(2, $normalizer->calls);
        $this->assertSame(1, HookFactoryChildDataFixture::$factoryCalls);
    }

    /**
     * Test post-validation hooks can add unvalidated values that still cast correctly.
     */
    public function testAfterValidationReconcilesHookAddedNestedDataWithoutValidatingIt(): void
    {
        $data = HookReconciliationParentDataFixture::factory()
            ->alwaysValidate()
            ->afterValidation(static fn (array $payload): array => [
                ...$payload,
                'child' => ['name' => 123],
            ])
            ->from([]);

        $this->assertInstanceOf(HookReconciliationChildDataFixture::class, $data->child);
        $this->assertSame('123', $data->child->name);
    }

    /**
     * Test validation hooks reselect morphs before compiling their rules.
     */
    public function testBeforeValidationReconcilesMorphSelectionForRulesAndConstruction(): void
    {
        try {
            HookMorphParentDataFixture::factory()
                ->alwaysValidate()
                ->beforeValidation(static fn (): array => [
                    'asset' => [
                        'type' => 'video',
                        'duration' => 123,
                    ],
                ])
                ->from([
                    'asset' => [
                        'type' => 'image',
                        'width' => 640,
                    ],
                ]);
            $this->fail('Expected the reselected morph rules to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('asset.duration', $exception->errors());
            $this->assertArrayNotHasKey('asset.width', $exception->errors());
        }

        $data = HookMorphParentDataFixture::factory()
            ->alwaysValidate()
            ->beforeValidation(static fn (): array => [
                'asset' => [
                    'type' => 'video',
                    'duration' => 'one minute',
                ],
            ])
            ->from([
                'asset' => [
                    'type' => 'image',
                    'width' => 640,
                ],
            ]);

        $this->assertInstanceOf(HookVideoDataFixture::class, $data->asset);
        $this->assertSame('one minute', $data->asset->duration);
    }

    /**
     * Test hook-added models use fixed normalization without custom normalizer replay.
     */
    public function testBeforeValidationUsesFixedModelNormalizationForChangedValues(): void
    {
        $model = new HookSourceModel;
        $model->setRawAttributes(['name' => 'Taylor']);

        $data = HookReconciliationParentDataFixture::factory()
            ->alwaysValidate()
            ->beforeValidation(static fn (array $payload): array => [
                ...$payload,
                'child' => $model,
            ])
            ->from([]);

        $this->assertSame('Taylor', $data->child->name);
    }

    /**
     * Test validation hooks replace per-item mapping overrides.
     */
    public function testBeforeValidationReconcilesCollectionItemMappings(): void
    {
        $data = HookMappedCollectionDataFixture::factory()
            ->alwaysValidate()
            ->beforeValidation(static fn (): array => [
                'items' => [
                    ['email_address' => 'new@example.com'],
                ],
            ])
            ->from([
                'items' => [
                    ['email' => 'old@example.com'],
                ],
            ]);

        $this->assertSame('new@example.com', $data->items[0]->email);

        try {
            HookMappedCollectionDataFixture::factory()
                ->alwaysValidate()
                ->beforeValidation(static fn (): array => [
                    'items' => [[]],
                ])
                ->from([
                    'items' => [
                        ['email' => 'old@example.com'],
                    ],
                ]);
            $this->fail('Expected the removed item property to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.email_address', $exception->errors());
            $this->assertArrayNotHasKey('items.0.email', $exception->errors());
        }
    }

    /**
     * Test class-owned and factory Validator hooks both run at the root.
     */
    public function testClassAndFactoryValidatorHooksRunInOrder(): void
    {
        LifecycleValidatorHooksDataFixture::$calls = [];
        $this->app->instance(
            ValidationLifecycleDependency::class,
            new ValidationLifecycleDependency,
        );

        $data = LifecycleValidatorHooksDataFixture::factory()
            ->alwaysValidate()
            ->withValidator(function (): void {
                LifecycleValidatorHooksDataFixture::$calls[] = 'factory-with-validator';
            })
            ->from(['value' => 'valid']);

        $this->assertSame('valid', $data->value);
        $this->assertSame([
            'class-with-validator',
            'factory-with-validator',
            'class-after',
        ], LifecycleValidatorHooksDataFixture::$calls);
    }

    /**
     * Test nested messages and labels keyed by PHP property names follow each item's mapped wire path.
     */
    public function testTranslatesNestedMessagesAndAttributesToObservedWirePaths(): void
    {
        $this->app->instance(
            ValidationLifecycleDependency::class,
            new ValidationLifecycleDependency(
                message: 'Invalid :attribute.',
                attribute: 'display name',
            ),
        );

        try {
            LifecycleMessagesParentDataFixture::validateAndCreate([
                'children' => [
                    ['profile' => ['name' => 123]],
                    ['profile' => ['name' => 456]],
                ],
            ]);
            $this->fail('Expected nested validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Invalid display name.'],
                $exception->errors()['children.0.profile.name'],
            );
            $this->assertSame(
                ['Invalid display name.'],
                $exception->errors()['children.1.profile.name'],
            );
        }
    }

    /**
     * Test collection items get the same attribute names whether their rules compile to one wildcard or per item.
     */
    public function testCollectionAttributeNamesMatchForUniformAndDivergentItems(): void
    {
        $messages = [];

        foreach ([
            'uniform' => [['first_name' => 1], ['first_name' => 2]],
            'divergent' => [['first_name' => 1], ['first_name' => 2, 'strict' => true]],
        ] as $case => $items) {
            try {
                NamedItemsDataFixture::validate(['items' => $items]);
                $this->fail("Expected the {$case} items to fail validation.");
            } catch (ValidationException $exception) {
                $messages[$case] = $exception->errors()['items.0.first_name'];
            }
        }

        $this->assertSame(['The items.0.first name field must be a string.'], $messages['uniform']);
        $this->assertSame($messages['uniform'], $messages['divergent']);
    }

    /**
     * Test a formatter set in a validator hook replaces the collection attribute formatter.
     */
    public function testValidatorHooksCanReplaceTheCollectionAttributeFormatter(): void
    {
        try {
            NamedItemsDataFixture::factory()
                ->withValidator(static function (Validator $validator): void {
                    $validator->setImplicitAttributesFormatter(static fn (string $attribute): string => strtoupper($attribute));
                })
                ->validate(['items' => [['first_name' => 1]]]);
            $this->fail('Expected the item to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['The ITEMS.0.FIRST_NAME field must be a string.'],
                $exception->errors()['items.0.first_name'],
            );
        }
    }

    /**
     * Test lifecycle methods override declarative validation-failure attributes.
     */
    public function testLifecycleMethodsOverrideFailureAttributes(): void
    {
        $this->app->instance(
            ValidationLifecycleDependency::class,
            new ValidationLifecycleDependency(
                redirect: '/method-redirect',
                errorBag: 'method-bag',
            ),
        );

        try {
            MethodConfiguredFailureDataFixture::validateAndCreate([
                'first' => 1,
                'second' => 2,
            ]);
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertCount(2, $exception->errors());
            $this->assertSame('method-bag', $exception->errorBag);
            $this->assertSame('http://localhost/method-redirect', $exception->redirectTo);
        }
    }

    /**
     * Test declarative failure settings use route URLs and stop on first failure.
     */
    public function testUsesDeclarativeValidationFailureSettings(): void
    {
        $this->app->make(Registrar::class)
            ->get('/attribute-redirect', static fn (): string => 'ok')
            ->name('attribute-redirect');

        try {
            AttributeConfiguredFailureDataFixture::validateAndCreate([
                'first' => 1,
                'second' => 2,
            ]);
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertCount(1, $exception->errors());
            $this->assertSame('attribute-bag', $exception->errorBag);
            $this->assertSame('http://localhost/attribute-redirect', $exception->redirectTo);
        }
    }

    /**
     * Test null dependent values retain Laravel Validator semantics through Data.
     */
    public function testRequiredUnlessAcceptsNullAndMissingComparedFields(): void
    {
        $this->assertSame(
            ['status' => null],
            NullDependentValidationDataFixture::validate(['status' => null]),
        );
        $this->assertSame([], NullDependentValidationDataFixture::validate([]));
    }

    /**
     * Test successful validate-only Precognition exits before construction.
     */
    public function testPrecognitionValidateOnlyExitsBeforeConstruction(): void
    {
        PrecognitiveValidatedDataFixture::$constructorCalls = 0;
        $beforeCreationCalls = 0;
        $request = Request::create('/', 'POST', ['value' => 'valid']);
        $request->attributes->set('precognitive', true);
        $request->headers->set('Precognition-Validate-Only', 'value');

        try {
            PrecognitiveValidatedDataFixture::factory()
                ->beforeCreation(function (array $properties) use (&$beforeCreationCalls): array {
                    ++$beforeCreationCalls;

                    return $properties;
                })
                ->from($request);
            $this->fail('Expected Precognition to abort with a successful response.');
        } catch (HttpException $exception) {
            $this->assertSame(204, $exception->getStatusCode());
            $this->assertSame(
                'true',
                $exception->getHeaders()['Precognition-Success'],
            );
        }

        $this->assertSame(0, $beforeCreationCalls);
        $this->assertSame(0, PrecognitiveValidatedDataFixture::$constructorCalls);
    }

    /**
     * Test a full-form precognitive request still constructs its data object.
     */
    public function testFullPrecognitiveRequestContinuesThroughConstruction(): void
    {
        PrecognitiveValidatedDataFixture::$constructorCalls = 0;
        $request = Request::create('/', 'POST', ['value' => 'valid']);
        $request->attributes->set('precognitive', true);

        $data = PrecognitiveValidatedDataFixture::from($request);

        $this->assertSame('valid', $data->value);
        $this->assertSame(1, PrecognitiveValidatedDataFixture::$constructorCalls);
    }

    /**
     * Test class after callbacks run before the Precognition success check.
     */
    public function testClassAfterCallbacksCanFailPrecognition(): void
    {
        $request = Request::create('/', 'POST', ['value' => 'valid']);
        $request->attributes->set('precognitive', true);
        $request->headers->set('Precognition-Validate-Only', 'value');

        try {
            PrecognitiveAfterCallbackDataFixture::from($request);
            $this->fail('Expected the class after callback to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Rejected by the after callback.'],
                $exception->errors()['value'],
            );
        }
    }

    /**
     * Test Precognition filtering does not make declared fields unknown.
     */
    public function testPrecognitionUnknownFieldsUsesUnfilteredRules(): void
    {
        $request = Request::create('/', 'POST', [
            'name' => [],
            'email' => 'taylor@example.com',
        ]);
        $request->attributes->set('precognitive', true);
        $request->headers->set('Precognition-Validate-Only', 'name');

        try {
            PrecognitiveStrictDataFixture::from($request);
            $this->fail('Expected the selected field to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
            $this->assertArrayNotHasKey('email', $exception->errors());
        }
    }

    /**
     * Test Precognition retains wildcard identity for selected Data rules.
     */
    public function testPrecognitionRetainsWildcardIdentityForSelectedDataRules(): void
    {
        $request = Request::create('/', 'POST', [
            'items' => [
                ['item_code' => 'duplicate'],
                ['item_code' => 'duplicate'],
            ],
        ]);
        $request->attributes->set('precognitive', true);
        $request->headers->set('Precognition-Validate-Only', 'items.1.item_code');

        try {
            PrecognitiveDistinctDataFixture::from($request);
            $this->fail('Expected the selected duplicate field to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'items.1.item_code' => [
                    'The items.1.item code field has a duplicate value.',
                ],
            ], $exception->errors());
        }
    }
}

class PresentCollectionDataFixture extends Data
{
    /**
     * Create a fixture with each kind of collection presence.
     */
    public function __construct(
        #[DataCollectionOf(SimpleData::class)]
        public array $items,
        #[DataCollectionOf(SimpleData::class)]
        public ?array $nullableItems,
        #[DataCollectionOf(SimpleData::class)]
        public Optional|array $optionalItems,
        #[DataCollectionOf(SimpleData::class), Required]
        public array $requiredItems,
        public array $plain,
    ) {
    }
}

#[MergeValidationRules]
class MergedRequiredCollectionDataFixture extends Data
{
    /**
     * Create a fixture whose collection also has a class rule.
     */
    public function __construct(
        #[DataCollectionOf(SimpleData::class)]
        public array $items,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        return ['items' => ['required']];
    }
}

class ReplacedCollectionRulesDataFixture extends Data
{
    /**
     * Create a fixture whose collection rules are replaced.
     */
    public function __construct(
        #[DataCollectionOf(SimpleData::class)]
        public array $items,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        return ['items' => ['array']];
    }
}

class ValidatedDataFixture extends Data
{
    public function __construct(
        public int $id,
        public ?string $nickname,
        public string|Optional $note,
        public string $label = 'default',
    ) {
    }
}

class UnionTypeRuleDataFixture extends Data
{
    /**
     * Create a fixture whose unions hold scalars, containers, and data objects.
     *
     * @param Collection<int, SimpleData>|Optional|string $collectionOrString
     */
    public function __construct(
        public string|SimpleData|Optional $stringOrData,
        #[Min(5)]
        public int|SimpleData|Optional $intOrData,
        public Collection|array|Optional $container,
        public array|SimpleData|Optional $arrayOrData,
        public Collection|string|Optional $collectionOrString,
    ) {
    }
}

class ConfirmedPasswordDataFixture extends Data
{
    /**
     * Create a confirmed password fixture.
     */
    public function __construct(
        #[Confirmed]
        public string $password,
    ) {
    }
}

class ConditionalNameDataFixture extends Data
{
    /**
     * Create a conditional name fixture.
     */
    public function __construct(
        public ?string $name = null,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        return ['name' => ['nullable', 'required_if:mode,admin']];
    }
}

class MappedConditionalNameDataFixture extends Data
{
    /**
     * Create a dot-mapped conditional name fixture.
     */
    public function __construct(
        #[MapInputName('profile.name')]
        public ?string $name = null,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        return ['name' => ['nullable', 'required_if:profile.mode,strict']];
    }
}

class MaxStringRuleInferrer implements RuleInferrer
{
    /**
     * Limit strings that have no maximum yet.
     */
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        if ($rules->hasType(StringType::class) && ! $rules->hasType(Max::class)) {
            $rules->add(new Max(255));
        }

        return $rules;
    }
}

class OptionalNicknameRuleInferrer implements RuleInferrer
{
    /**
     * Make the nickname optional.
     */
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        if ($property->name === 'nickname') {
            $rules->removeType(Required::class)->add(new Sometimes);
        }

        return $rules;
    }
}

class ScopedMaxRuleInferrer implements RuleInferrer
{
    /**
     * Create a scoped maximum-length rule inferrer.
     */
    public function __construct(
        public readonly int $max,
    ) {
    }

    /**
     * Limit the name to this inferrer's maximum.
     */
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        if ($property->name === 'name') {
            $rules->add(new Max($this->max));
        }

        return $rules;
    }
}

class RemoveMaxRuleInferrer implements RuleInferrer
{
    /**
     * Remove every maximum rule.
     */
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        return $rules->removeType(Max::class);
    }
}

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class CountingRuleAttribute extends CustomValidationAttribute
{
    public static int $evaluations = 0;

    /**
     * Get the Validator rules.
     */
    public function getRules(ValidationPath $path): array
    {
        ++static::$evaluations;

        return ['alpha'];
    }
}

class UnboundReferenceDataFixture extends Data
{
    /**
     * Create an unbound reference fixture.
     */
    public function __construct(
        #[Max(new ContainerReference('unbound-limit'))]
        public string $name,
    ) {
    }
}

class CountedReferenceDataFixture extends Data
{
    /**
     * Create a counted reference fixture.
     */
    public function __construct(
        #[Max(new ContainerReference('counted-limit')), CountingRuleAttribute]
        public string $name,
    ) {
    }
}

class ContextChildDataFixture extends Data
{
    /**
     * Create a context-recording child fixture.
     */
    public function __construct(
        public string $value,
    ) {
    }

    /**
     * Record the class rule context.
     */
    public static function rules(ValidationContext $context): array
    {
        ContextParentDataFixture::$contexts['child'] = [$context->payload, $context->fullPayload];

        return [];
    }
}

class ContextParentDataFixture extends Data
{
    /** @var array<string, array{mixed, mixed}> */
    public static array $contexts = [];

    /**
     * Create a context-recording parent fixture.
     */
    public function __construct(
        public ContextChildDataFixture $child,
    ) {
    }

    /**
     * Record the class rule context.
     */
    public static function rules(ValidationContext $context): array
    {
        static::$contexts['parent'] = [$context->payload, $context->fullPayload];

        return [];
    }
}

class PayloadLengthRuleInferrer implements RuleInferrer
{
    /**
     * Limit the name according to the item's long flag.
     */
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        if ($property->name === 'name') {
            $rules->add(new Max(($context->payload['long'] ?? false) ? 100 : 5));
        }

        return $rules;
    }
}

class RecordingContextRuleInferrer implements RuleInferrer
{
    /** @var array<string, list<ValidationContext>> */
    public static array $contexts = [];

    /**
     * Record the validation context each property is inferred with.
     */
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        static::$contexts[$property->name][] = $context;

        return $rules;
    }
}

class RuleInferrerDataFixture extends Data
{
    /**
     * Create a rule inferrer fixture.
     *
     * @param array<string, int> $meta
     */
    public function __construct(
        public string $name,
        public string $nickname,
        #[Max(20)]
        public string $code,
        #[ArrayType('id')]
        public array $meta,
        public int $age,
        #[Rule('required|string')]
        public string $ruled,
    ) {
    }
}

#[MergeValidationRules]
class MergedRuleInferrerDataFixture extends Data
{
    /**
     * Create a merged-rules inferrer fixture.
     */
    public function __construct(
        public string $name,
        public ?string $other = null,
    ) {
    }

    /**
     * Get the class-owned validation rules.
     */
    public static function rules(): array
    {
        return ['name' => ['required_with:other']];
    }
}

class RuleInferrerItemDataFixture extends Data
{
    /**
     * Create a rule inferrer item fixture.
     */
    public function __construct(
        public string $name,
        public bool $long = false,
    ) {
    }
}

class RuleInferrerParentDataFixture extends Data
{
    /**
     * Create a rule inferrer parent fixture.
     *
     * @param array<array-key, RuleInferrerItemDataFixture> $items
     */
    public function __construct(
        #[DataCollectionOf(RuleInferrerItemDataFixture::class)]
        public array $items,
    ) {
    }
}

class ValidatedDtoFixture extends Dto
{
    public function __construct(
        public int $id,
    ) {
    }
}

class ValidatedResourceFixture extends Resource
{
    public function __construct(
        public int $id,
    ) {
    }
}

class ValidatedChildDataFixture extends Data
{
    public function __construct(
        #[MapInputName('profile.name')]
        public string $name,
    ) {
    }
}

class MappedValueDataFixture extends Data
{
    /**
     * Create a fixture with a mapped input name.
     */
    public function __construct(
        #[MapInputName('wire')]
        public string $value,
    ) {
    }
}

class OptionalMappedValueDataFixture extends Data
{
    /**
     * Create a fixture with an Optional mapped input name.
     */
    public function __construct(
        #[MapInputName('wire')]
        public string|Optional $value,
    ) {
    }
}

#[FailOnUnknownFields]
class StrictMappedValueDataFixture extends Data
{
    /**
     * Create a strict fixture with a mapped input name.
     */
    public function __construct(
        #[MapInputName('wire')]
        public string $value,
    ) {
    }
}

enum ValidationStatusEnumFixture: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum ValidationPriorityEnumFixture: int
{
    case High = 1;
    case Low = 2;
}

class EnumValidatedDataFixture extends Data
{
    /**
     * Create a fixture with string- and integer-backed enum properties.
     */
    public function __construct(
        public ValidationStatusEnumFixture $status,
        public ValidationPriorityEnumFixture $priority,
    ) {
    }
}

class RestrictedEnumDataFixture extends Data
{
    /**
     * Create a fixture whose declared enum rule allows one case.
     */
    public function __construct(
        #[EnumAttribute(ValidationStatusEnumFixture::class, only: [ValidationStatusEnumFixture::Active])]
        public ValidationStatusEnumFixture $status,
    ) {
    }
}

class EnumItemDataFixture extends Data
{
    /**
     * Create an enum collection item fixture.
     */
    public function __construct(
        public ValidationStatusEnumFixture $status,
    ) {
    }

    /**
     * Restrict the status to the case named in the item's input.
     */
    public static function rules(ValidationContext $context): array
    {
        $only = $context->payload['only'] ?? null;

        return $only === null
            ? []
            : ['status' => [(new EnumRule(ValidationStatusEnumFixture::class))->only(ValidationStatusEnumFixture::from($only))]];
    }
}

class EnumItemsParentDataFixture extends Data
{
    /**
     * Create a fixture holding enum items.
     *
     * @param array<array-key, EnumItemDataFixture> $items
     */
    public function __construct(
        #[DataCollectionOf(EnumItemDataFixture::class)]
        public array $items,
    ) {
    }
}

class ValidatedParentDataFixture extends Data
{
    /**
     * Create a validated parent fixture.
     *
     * @param array<array-key, ValidatedChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(ValidatedChildDataFixture::class)]
        public array $children,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['children.*.name' => ['min:5']];
    }
}

class DynamicRulesChildDataFixture extends Data
{
    public function __construct(
        public string $name,
    ) {
    }

    /**
     * Get item-specific validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['name' => ['in:' . $context->payload['name']]];
    }
}

class DynamicRulesParentDataFixture extends Data
{
    /**
     * Create a dynamic-rules parent fixture.
     *
     * @param array<array-key, DynamicRulesChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(DynamicRulesChildDataFixture::class)]
        public array $children,
    ) {
    }
}

class NestedDynamicRulesItemDataFixture extends Data
{
    public function __construct(
        public DynamicRulesChildDataFixture $child,
    ) {
    }
}

class NestedDynamicRulesParentDataFixture extends Data
{
    /**
     * Create a nested dynamic-rules parent fixture.
     *
     * @param array<array-key, NestedDynamicRulesItemDataFixture> $items
     */
    public function __construct(
        #[DataCollectionOf(NestedDynamicRulesItemDataFixture::class)]
        public array $items,
    ) {
    }
}

class NestedDynamicCollectionItemDataFixture extends Data
{
    /**
     * Create a nested dynamic-collection item fixture.
     *
     * @param array<array-key, DynamicRulesChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(DynamicRulesChildDataFixture::class)]
        public array $children,
    ) {
    }
}

class NestedDynamicCollectionParentDataFixture extends Data
{
    /**
     * Create a nested dynamic-collection parent fixture.
     *
     * @param array<array-key, NestedDynamicCollectionItemDataFixture> $groups
     */
    public function __construct(
        #[DataCollectionOf(NestedDynamicCollectionItemDataFixture::class)]
        public array $groups,
    ) {
    }
}

#[MergeValidationRules]
class NestedDistinctChildDataFixture extends Data
{
    public function __construct(
        #[Distinct]
        public string $name,
    ) {
    }

    /**
     * Get item-specific validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['name' => ['in:' . $context->payload['name']]];
    }
}

class NestedDistinctGroupDataFixture extends Data
{
    /**
     * Create a nested distinct group fixture.
     *
     * @param array<array-key, NestedDistinctChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(NestedDistinctChildDataFixture::class)]
        public array $children,
    ) {
    }
}

class NestedDistinctParentDataFixture extends Data
{
    /**
     * Create a nested distinct parent fixture.
     *
     * @param array<array-key, NestedDistinctGroupDataFixture> $groups
     */
    public function __construct(
        #[DataCollectionOf(NestedDistinctGroupDataFixture::class)]
        public array $groups,
    ) {
    }
}

class FinishedDistinctPropertyItemDataFixture extends Data
{
    public function __construct(
        public NestedDistinctChildDataFixture $child,
    ) {
    }
}

class FinishedDistinctPropertyParentDataFixture extends Data
{
    /**
     * Create a finished distinct property parent fixture.
     *
     * @param array<array-key, FinishedDistinctPropertyItemDataFixture> $items
     */
    public function __construct(
        #[DataCollectionOf(FinishedDistinctPropertyItemDataFixture::class)]
        public array $items,
    ) {
    }
}

class FinishedDistinctContainerItemDataFixture extends Data
{
    /**
     * Create a finished distinct container item fixture.
     *
     * @param Collection<array-key, NestedDistinctChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(NestedDistinctChildDataFixture::class)]
        public Collection $children,
    ) {
    }
}

class FinishedDistinctContainerParentDataFixture extends Data
{
    /**
     * Create a finished distinct container parent fixture.
     *
     * @param array<array-key, FinishedDistinctContainerItemDataFixture> $items
     */
    public function __construct(
        #[DataCollectionOf(FinishedDistinctContainerItemDataFixture::class)]
        public array $items,
    ) {
    }
}

#[MergeValidationRules]
class MixedNestedDistinctChildDataFixture extends Data
{
    public function __construct(
        #[Distinct]
        public string $name,
        public string $category,
    ) {
    }

    /**
     * Get item-specific validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['category' => ['in:' . $context->payload['category']]];
    }
}

class MixedNestedDistinctGroupDataFixture extends Data
{
    /**
     * Create a mixed nested distinct group fixture.
     *
     * @param array<array-key, MixedNestedDistinctChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(MixedNestedDistinctChildDataFixture::class)]
        public array $children,
    ) {
    }
}

class MixedNestedDistinctParentDataFixture extends Data
{
    /**
     * Create a mixed nested distinct parent fixture.
     *
     * @param array<array-key, MixedNestedDistinctGroupDataFixture> $groups
     */
    public function __construct(
        #[DataCollectionOf(MixedNestedDistinctGroupDataFixture::class)]
        public array $groups,
    ) {
    }
}

class HookRulesChildDataFixture extends Data
{
    public function __construct(
        public string $name,
    ) {
    }
}

class HookRulesParentDataFixture extends Data
{
    /**
     * Create a rule-hook parent fixture.
     *
     * @param array<array-key, HookRulesChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(HookRulesChildDataFixture::class)]
        public array $children,
    ) {
    }
}

abstract class OverlappingClassRulesParentDataFixture extends Data
{
    /**
     * Create an overlapping-rules parent fixture.
     *
     * @param array<array-key, HookRulesChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(HookRulesChildDataFixture::class)]
        public array $children,
    ) {
    }
}

class ReplaceExactThenWildcardRulesParentDataFixture extends OverlappingClassRulesParentDataFixture
{
    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        return [
            'children.0.name' => ['min:2'],
            'children.*.name' => ['max:9'],
        ];
    }
}

class ReplaceWildcardThenExactRulesParentDataFixture extends OverlappingClassRulesParentDataFixture
{
    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        return [
            'children.*.name' => ['max:9'],
            'children.0.name' => ['min:2'],
        ];
    }
}

#[MergeValidationRules]
class MergeExactThenWildcardRulesParentDataFixture extends OverlappingClassRulesParentDataFixture
{
    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        return [
            'children.0.name' => ['min:2'],
            'children.*.name' => ['max:9'],
        ];
    }
}

#[MergeValidationRules]
class MergeWildcardThenExactRulesParentDataFixture extends OverlappingClassRulesParentDataFixture
{
    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        return [
            'children.*.name' => ['max:9'],
            'children.0.name' => ['min:2'],
        ];
    }
}

#[MergeValidationRules]
class MergePresenceWildcardRulesParentDataFixture extends Data
{
    /**
     * Create a merged presence-rules parent fixture.
     *
     * @param array<array-key, HookRulesChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(HookRulesChildDataFixture::class)]
        public array $children,
        public bool $enabled,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        return [
            'children.*.name' => ['required_if:enabled,true', 'max:9'],
        ];
    }
}

abstract class DynamicMorphBaseDataFixture extends Data implements PropertyMorphableData
{
    public function __construct(
        #[PropertyForMorph]
        public string $type,
    ) {
    }

    /**
     * Resolve the concrete fixture class.
     */
    public static function morph(array $properties): ?string
    {
        return $properties['type'] === 'named'
            ? DynamicMorphChildDataFixture::class
            : null;
    }
}

class DynamicMorphChildDataFixture extends DynamicMorphBaseDataFixture
{
    public function __construct(
        string $type,
        public string $name,
    ) {
        parent::__construct($type);
    }

    /**
     * Get item-specific validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['name' => ['in:' . $context->payload['name']]];
    }
}

class DynamicMorphParentDataFixture extends Data
{
    /**
     * Create a dynamic-morph parent fixture.
     *
     * @param array<array-key, DynamicMorphBaseDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(DynamicMorphBaseDataFixture::class)]
        public array $children,
    ) {
    }
}

class ValidatedLazyParentDataFixture extends Data
{
    /**
     * Create a validated lazy parent fixture.
     *
     * @param LazyCollection<int, ValidatedChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(ValidatedChildDataFixture::class)]
        public LazyCollection $children,
    ) {
    }
}

class AttributeValidatedDataFixture extends Data
{
    public function __construct(
        #[Required, StringType]
        public string $name = 'default',
    ) {
    }
}

class SometimesRequiredWithDataFixture extends Data
{
    #[RequiredWith('other')]
    public string|Optional $inferred;

    #[Sometimes, RequiredWith('other')]
    public string|Optional $declared;

    public ?string $other;
}

class OptionalNameDataFixture extends Data
{
    public ?string $name;
}

class UnreadableParentDataFixture extends Data
{
    public OptionalNameDataFixture $child;

    public ?OptionalNameDataFixture $nullableChild;

    public OptionalNameDataFixture|Optional $optionalChild;

    /** @var array<array-key, OptionalNameDataFixture> */
    public array $children;
}

abstract class NullableMorphBaseDataFixture extends Data implements PropertyMorphableData
{
    #[PropertyForMorph]
    public ?string $type;

    /**
     * Resolve the concrete class only for the concrete type.
     */
    public static function morph(array $properties): ?string
    {
        return $properties['type'] === 'concrete' ? NullableMorphConcreteDataFixture::class : null;
    }
}

class NullableMorphConcreteDataFixture extends NullableMorphBaseDataFixture
{
}

class NamedItemDataFixture extends Data
{
    public string $first_name;

    /**
     * Require a longer name for a strict item.
     */
    public static function rules(ValidationContext $context): array
    {
        return ($context->payload['strict'] ?? false) ? ['first_name' => ['string', 'min:3']] : [];
    }
}

class NamedItemsDataFixture extends Data
{
    /** @var array<array-key, NamedItemDataFixture> */
    public array $items;
}

class UnvalidatedArrayKeysDataFixture extends Data
{
    public function __construct(
        public array $meta,
    ) {
    }

    /**
     * Get the validated child key inside the array.
     */
    public static function rules(): array
    {
        return ['meta.known' => ['nullable', 'string']];
    }
}

class ComputedChildDataFixture extends Data
{
    #[Computed]
    public string $full;

    /**
     * Create a computed child fixture.
     */
    public function __construct(
        public string $first,
    ) {
        $this->full = $first . '!';
    }
}

class ComputedParentDataFixture extends Data
{
    /**
     * Create a computed parent fixture.
     */
    public function __construct(
        public ComputedChildDataFixture $child,
    ) {
    }
}

class ClassRulesValidatedDataFixture extends Data
{
    public function __construct(
        public string $name,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['name' => ['min:3']];
    }
}

#[MergeValidationRules]
class MergedRequiringRulesDataFixture extends Data
{
    public function __construct(
        public string $value,
        public bool $enabled,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        return [
            'value' => ['required_if:enabled,true', 'max:10'],
        ];
    }
}

#[MergeValidationRules]
class MergedPresentRuleDataFixture extends Data
{
    public function __construct(
        public string $value,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        return ['value' => ['present']];
    }
}

#[MergeValidationRules]
class ExplicitAndMergedRequiringRulesDataFixture extends Data
{
    public function __construct(
        #[Required]
        public string $value,
        public bool $enabled,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        return ['value' => ['required_if:enabled,true']];
    }
}

class UnauthorizedValidatedDataFixture extends Data
{
    public function __construct(
        public int $id,
    ) {
    }

    /**
     * Determine whether the current Request may create the data object.
     */
    public static function authorize(): bool
    {
        return false;
    }
}

class DeniedDirectFactoryDataFixture extends Data
{
    public static int $factoryCalls = 0;

    public function __construct(
        public int $id,
    ) {
    }

    /**
     * Determine whether the current Request may create the data object.
     */
    public static function authorize(): AuthorizationResponse
    {
        return AuthorizationResponse::denyWithStatus(
            403,
            'Denied by policy.',
            'policy-code',
        );
    }

    /**
     * Create a finished object from a Request.
     */
    public static function fromRequest(Request $request): static
    {
        ++self::$factoryCalls;

        return new static((int) $request->input('id'));
    }
}

class DirectFactoryValidatedDataFixture extends Data
{
    public function __construct(
        public int $id,
    ) {
    }

    /**
     * Create a finished object through a named factory.
     */
    public static function fromArray(array $payload): static
    {
        return new static(99);
    }
}

class RuleIntrospectionLifecycleDataFixture extends Data
{
    public static int $rulesCalls = 0;

    public static int $messagesCalls = 0;

    public static int $attributesCalls = 0;

    public function __construct(
        public string $value,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(): array
    {
        ++self::$rulesCalls;

        return ['value' => ['required']];
    }

    /**
     * Get custom validation messages.
     */
    public static function messages(): array
    {
        ++self::$messagesCalls;

        return [];
    }

    /**
     * Get custom validation attribute labels.
     */
    public static function attributes(): array
    {
        ++self::$attributesCalls;

        return [];
    }
}

class FinishedValidatedChildDataFixture extends Data
{
    public function __construct(
        #[Required, StringType]
        public string $name,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['name' => ['min:3']];
    }
}

#[FailOnUnknownFields]
class FinishedValidatedParentDataFixture extends Data
{
    public function __construct(
        public FinishedValidatedChildDataFixture $child,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['child.name' => ['required']];
    }
}

class FinishedExcludedAttributeDataFixture extends Data
{
    /**
     * Create a fixture excluding its child through a validation attribute.
     */
    public function __construct(
        public bool $skip,
        #[ExcludeIf('skip', true)]
        public ?FinishedValidatedChildDataFixture $child = null,
    ) {
    }
}

class FinishedExcludedRuleDataFixture extends Data
{
    /**
     * Create a fixture excluding its child through a rule attribute.
     */
    public function __construct(
        public bool $skip,
        #[Rule('exclude_if:skip,true')]
        public ?FinishedValidatedChildDataFixture $child = null,
    ) {
    }
}

class FinishedExcludedClassRuleDataFixture extends Data
{
    /**
     * Create a fixture excluding its child through a class rule.
     */
    public function __construct(
        public bool $skip,
        public ?FinishedValidatedChildDataFixture $child = null,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['child' => ['exclude_if:skip,true']];
    }
}

class FinishedCustomRuleDataFixture extends Data
{
    /**
     * Create a fixture checking its child with a custom rule.
     */
    public function __construct(
        #[Rule(new RejectsBlockedChildRule)]
        public FinishedValidatedChildDataFixture $child,
    ) {
    }
}

class FinishedProhibitedMessageDataFixture extends Data
{
    /**
     * Create a fixture prohibiting its child with a custom message.
     */
    public function __construct(
        public ?FinishedValidatedChildDataFixture $child = null,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['child' => ['prohibited']];
    }

    /**
     * Get class-owned validation messages.
     */
    public static function messages(): array
    {
        return ['child.prohibited' => 'A child cannot be given.'];
    }
}

class FinishedProhibitedItemMessageDataFixture extends Data
{
    /**
     * Create a fixture prohibiting its first item with a custom message.
     *
     * @param array<array-key, FinishedValidatedChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(FinishedValidatedChildDataFixture::class)]
        public array $children,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['children.0' => ['prohibited']];
    }

    /**
     * Get class-owned validation messages.
     */
    public static function messages(): array
    {
        return ['children.0.prohibited' => 'The first child cannot be given.'];
    }
}

class FinishedProhibitedItemsMessageDataFixture extends Data
{
    /**
     * Create a fixture prohibiting every item with a custom message.
     *
     * @param array<array-key, FinishedValidatedChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(FinishedValidatedChildDataFixture::class)]
        public array $children,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['children.*' => ['prohibited']];
    }

    /**
     * Get class-owned validation messages.
     */
    public static function messages(): array
    {
        return ['children.*.prohibited' => 'No child can be given.'];
    }
}

class MappedFieldMessageChildDataFixture extends Data
{
    /**
     * Create a child fixture whose name has a different input name.
     */
    public function __construct(
        #[MapInputName('full_name')]
        public string $name,
        public int $age,
    ) {
    }
}

class MappedFieldMessageParentDataFixture extends Data
{
    /**
     * Create a parent fixture with a mapped nested field.
     */
    public function __construct(
        public MappedFieldMessageChildDataFixture $child,
    ) {
    }

    /**
     * Get class-owned validation messages.
     */
    public static function messages(): array
    {
        return ['child.name' => 'Supply a name.'];
    }
}

class FinishedExcludedItemDataFixture extends Data
{
    /**
     * Create a fixture excluding one finished item.
     *
     * @param array<array-key, FinishedValidatedChildDataFixture> $children
     */
    public function __construct(
        public bool $skip,
        #[DataCollectionOf(FinishedValidatedChildDataFixture::class)]
        public array $children,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['children.0' => ['exclude_if:skip,true']];
    }
}

class FinishedExcludedItemsDataFixture extends Data
{
    /**
     * Create a fixture excluding every item.
     *
     * @param array<array-key, FinishedValidatedChildDataFixture> $children
     */
    public function __construct(
        public bool $skip,
        #[DataCollectionOf(FinishedValidatedChildDataFixture::class)]
        public array $children,
    ) {
    }

    /**
     * Get class-owned validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['children.*' => ['exclude_if:skip,true']];
    }
}

class RejectsBlockedChildRule implements ValidationRuleContract
{
    /**
     * Reject a child named "blocked".
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof FinishedValidatedChildDataFixture && $value->name === 'blocked') {
            $fail('The child is blocked.');
        }
    }
}

class FinishedNestedItemDataFixture extends Data
{
    public function __construct(
        public FinishedValidatedChildDataFixture $child,
    ) {
    }
}

class FinishedNestedParentDataFixture extends Data
{
    /**
     * Create a finished nested parent fixture.
     *
     * @param array<array-key, FinishedNestedItemDataFixture> $items
     */
    public function __construct(
        #[DataCollectionOf(FinishedNestedItemDataFixture::class)]
        public array $items,
    ) {
    }
}

class FinishedDataCollectionItemDataFixture extends Data
{
    public function __construct(
        #[DataCollectionOf(FinishedValidatedChildDataFixture::class)]
        public DataCollection $children,
    ) {
    }
}

class FinishedDataCollectionParentDataFixture extends Data
{
    /**
     * Create a finished data collection parent fixture.
     *
     * @param array<array-key, FinishedDataCollectionItemDataFixture> $items
     */
    public function __construct(
        #[DataCollectionOf(FinishedDataCollectionItemDataFixture::class)]
        public array $items,
    ) {
    }
}

class FinishedNativeCollectionItemDataFixture extends Data
{
    /**
     * Create a finished native collection item fixture.
     *
     * @param Collection<array-key, FinishedValidatedChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(FinishedValidatedChildDataFixture::class)]
        public Collection $children,
    ) {
    }
}

class FinishedNativeCollectionParentDataFixture extends Data
{
    /**
     * Create a finished native collection parent fixture.
     *
     * @param array<array-key, FinishedNativeCollectionItemDataFixture> $items
     */
    public function __construct(
        #[DataCollectionOf(FinishedNativeCollectionItemDataFixture::class)]
        public array $items,
    ) {
    }
}

class FinishedNestedCollectionGroupDataFixture extends Data
{
    /**
     * Create a finished nested collection group fixture.
     *
     * @param array<array-key, FinishedValidatedChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(FinishedValidatedChildDataFixture::class)]
        public array $children,
    ) {
    }
}

class FinishedNestedCollectionParentDataFixture extends Data
{
    /**
     * Create a finished nested collection parent fixture.
     *
     * @param array<array-key, FinishedNestedCollectionGroupDataFixture> $groups
     */
    public function __construct(
        #[DataCollectionOf(FinishedNestedCollectionGroupDataFixture::class)]
        public array $groups,
    ) {
    }
}

class DirectFinishedChildDataFixture extends Data
{
    public function __construct(
        public string $name,
    ) {
    }

    /**
     * Finish string payloads before validation.
     */
    public static function fromName(string $name): static
    {
        return new static($name);
    }

    /**
     * Get raw-value validation rules.
     */
    public static function rules(): array
    {
        return ['name' => ['in:valid']];
    }
}

class DirectFinishedParentDataFixture extends Data
{
    /**
     * Create a direct-finished parent fixture.
     *
     * @param array<array-key, DirectFinishedChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(DirectFinishedChildDataFixture::class)]
        public array $children,
    ) {
    }
}

#[FailOnUnknownFields]
class StrictValidatedDataFixture extends Data
{
    public function __construct(
        public string $name,
    ) {
    }
}

class NestedStrictParentDataFixture extends Data
{
    public function __construct(
        public StrictValidatedDataFixture $child,
    ) {
    }
}

abstract class StrictMorphBaseDataFixture extends Data implements PropertyMorphableData
{
    /**
     * Create the morph base fixture.
     */
    public function __construct(
        #[PropertyForMorph]
        public string $type,
    ) {
    }

    /**
     * Resolve the strict subtype.
     */
    public static function morph(array $properties): ?string
    {
        return $properties['type'] === 'strict' ? StrictMorphChildDataFixture::class : null;
    }
}

#[FailOnUnknownFields]
class StrictMorphChildDataFixture extends StrictMorphBaseDataFixture
{
    /**
     * Create the strict subtype fixture.
     */
    public function __construct(
        string $type,
        public string $name,
    ) {
        parent::__construct($type);
    }
}

#[FailOnUnknownFields]
class StrictNestedParentDataFixture extends Data
{
    public function __construct(
        public NonStrictValidatedChildDataFixture $child,
    ) {
    }
}

class NonStrictValidatedChildDataFixture extends Data
{
    public function __construct(
        public string $name,
    ) {
    }
}

class AuxiliaryParentDataFixture extends Data
{
    /**
     * Create an auxiliary parent fixture.
     *
     * @param array<array-key, AuxiliaryChildDataFixture> $items
     */
    public function __construct(
        #[DataCollectionOf(AuxiliaryChildDataFixture::class)]
        public array $items,
    ) {
    }
}

#[FailOnUnknownFields]
class AuxiliaryChildDataFixture extends Data
{
    public function __construct(
        public int $id,
        #[WithoutValidation]
        public string $serverUser,
        #[WithoutValidation]
        public array $meta,
        #[MapInputName('literal*'), WithoutValidation]
        public string $literalStar,
        #[WithoutValidation]
        public string|Optional $note,
    ) {
    }
}

#[FailOnUnknownFields]
class ContextualStrictDataFixture extends Data
{
    public function __construct(
        public string $name,
        #[Config('tests.data.server_user'), MapInputName('server_user')]
        public int $serverUser,
        #[Config('tests.data.context')]
        public array $context,
    ) {
    }
}

#[FailOnUnknownFields]
class UnstructuredStrictDataFixture extends Data
{
    public function __construct(
        public mixed $meta,
        public array $options,
    ) {
    }
}

#[FailOnUnknownFields]
class DynamicStrictValidatedDataFixture extends Data
{
    public function __construct(
        public string $name,
    ) {
    }

    /**
     * Add a dynamically validated input field.
     */
    public static function withValidator(Validator $validator): void
    {
        $validator->setRules([
            ...$validator->getRulesWithoutPlaceholders(),
            'nickname' => ['string'],
        ]);
    }
}

class FactoryValidationHooksDataFixture extends Data
{
    public function __construct(
        public string $value,
    ) {
    }
}

class HookReconciliationChildDataFixture extends Data
{
    public function __construct(
        #[StringType]
        public string $name,
    ) {
    }
}

class HookReconciliationParentDataFixture extends Data
{
    public function __construct(
        public HookReconciliationChildDataFixture|Optional $child,
    ) {
    }
}

class HookMappedScalarDataFixture extends Data
{
    public function __construct(
        #[MapInputName('email_address')]
        public string $email,
    ) {
    }
}

#[FailOnUnknownFields]
class ValidatedConstructorInputData extends Data
{
    #[Computed]
    public array $received;

    #[WithoutValidation]
    public ?string $note = null;

    /**
     * Create a fixture whose constructor parameters have no public data properties.
     *
     * @param array<string, mixed> $options
     */
    public function __construct(string $prefix, array $options = [], string $secret = 'none')
    {
        $this->received = ['prefix' => $prefix, 'options' => $options, 'secret' => $secret];
    }
}

class GovernedConstructorInputData extends ValidatedConstructorInputData
{
    /**
     * Get rules that govern two of the constructor inputs.
     */
    public static function rules(): array
    {
        return [
            'options.a' => ['integer'],
            'options.b' => ['exclude'],
            'secret' => ['exclude'],
        ];
    }
}

class ConstructorInputParentData extends Data
{
    /**
     * Create a parent whose rules reach nested constructor inputs.
     *
     * @param array<int, ValidatedConstructorInputData> $items
     */
    public function __construct(
        #[DataCollectionOf(ValidatedConstructorInputData::class)]
        public array $items,
        public bool $skip = false,
        public ?ValidatedConstructorInputData $child = null,
    ) {
    }

    /**
     * Get rules for nested constructor inputs and the excluded child.
     */
    public static function rules(): array
    {
        return [
            'items.*.secret' => ['exclude'],
            'child' => ['exclude_if:skip,true'],
        ];
    }
}

class HookMappedUnvalidatedDataFixture extends Data
{
    public function __construct(
        public int $id,
        #[MapInputName('nested.value'), WithoutValidation]
        public ?string $value = null,
    ) {
    }
}

class HookFactoryChildDataFixture extends Data
{
    public static int $factoryCalls = 0;

    public function __construct(
        public string $name,
    ) {
    }

    /**
     * Create a fixture from one token.
     */
    public static function fromToken(string $token): self
    {
        ++self::$factoryCalls;

        return new self("factory:{$token}");
    }
}

class HookFactoryParentDataFixture extends Data
{
    public function __construct(
        public HookFactoryChildDataFixture $child,
    ) {
    }
}

class HookTransformParentDataFixture extends Data
{
    public function __construct(
        public HookReconciliationChildDataFixture $changed,
        public HookFactoryChildDataFixture $sibling,
    ) {
    }
}

class HookCountingNormalizer implements Normalizer
{
    public int $calls = 0;

    public function normalize(mixed $value): null
    {
        ++$this->calls;

        return null;
    }
}

class EnvelopeRequestNormalizer implements Normalizer
{
    public int $calls = 0;

    /**
     * Read a request's input from its data envelope.
     */
    public function normalize(mixed $value): ?array
    {
        if (! $value instanceof Request) {
            return null;
        }

        ++$this->calls;

        return $value->input('data');
    }
}

abstract class HookMorphDataFixture extends Data implements PropertyMorphableData
{
    public function __construct(
        #[PropertyForMorph]
        public string $type,
    ) {
    }

    /**
     * Resolve the selected hook morph fixture.
     */
    public static function morph(array $properties): ?string
    {
        return match ($properties['type'] ?? null) {
            'image' => HookImageDataFixture::class,
            'video' => HookVideoDataFixture::class,
            default => null,
        };
    }
}

class HookImageDataFixture extends HookMorphDataFixture
{
    public function __construct(
        string $type,
        public int $width,
    ) {
        parent::__construct($type);
    }
}

class HookVideoDataFixture extends HookMorphDataFixture
{
    public function __construct(
        string $type,
        #[StringType]
        public string $duration,
    ) {
        parent::__construct($type);
    }
}

class HookMorphParentDataFixture extends Data
{
    public function __construct(
        public HookMorphDataFixture $asset,
    ) {
    }
}

class HookSourceModel extends Model
{
}

class HookMappedCollectionDataFixture extends Data
{
    /**
     * Create a mapped collection fixture.
     *
     * @param array<array-key, HookMappedScalarDataFixture> $items
     */
    public function __construct(
        #[DataCollectionOf(HookMappedScalarDataFixture::class)]
        public array $items,
    ) {
    }
}

class ValidationLifecycleDependency
{
    public function __construct(
        public string $message = 'Invalid value.',
        public string $attribute = 'value',
        public string $redirect = '/redirect',
        public string $errorBag = 'default',
    ) {
    }
}

class LifecycleValidatorHooksDataFixture extends Data
{
    /** @var list<string> */
    public static array $calls = [];

    public function __construct(
        public string $value,
    ) {
    }

    /**
     * Configure the root Validator.
     */
    public static function withValidator(
        Validator $validator,
        ?ValidationLifecycleDependency $dependency = null,
    ): void {
        self::$calls[] = $dependency instanceof ValidationLifecycleDependency
            ? 'class-with-validator'
            : 'missing-dependency';
    }

    /**
     * Get root Validator after callbacks.
     */
    public static function after(
        ValidationLifecycleDependency $dependency,
        Validator $validator,
    ): array {
        return [static function () use ($dependency, $validator): void {
            self::$calls[] = $dependency instanceof ValidationLifecycleDependency
                && $validator->getData()['value'] === 'valid'
                    ? 'class-after'
                    : 'invalid-after-context';
        }];
    }
}

class LifecycleMessagesChildDataFixture extends Data
{
    public function __construct(
        #[MapInputName('profile.name'), StringType]
        public string $name,
    ) {
    }

    /**
     * Get custom validation messages.
     */
    public static function messages(ValidationLifecycleDependency $dependency): array
    {
        return ['name.string' => $dependency->message];
    }

    /**
     * Get custom validation attribute labels.
     */
    public static function attributes(ValidationLifecycleDependency $dependency): array
    {
        return ['name' => $dependency->attribute];
    }
}

class LifecycleMessagesParentDataFixture extends Data
{
    /**
     * Create a lifecycle-messages parent fixture.
     *
     * @param array<array-key, LifecycleMessagesChildDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(LifecycleMessagesChildDataFixture::class)]
        public array $children,
    ) {
    }

    /**
     * Get fallback validation messages.
     */
    public static function messages(): array
    {
        return ['children.*.name.string' => 'Invalid parent :attribute.'];
    }

    /**
     * Get fallback validation attribute labels.
     */
    public static function attributes(): array
    {
        return ['children.*.name' => 'parent display name'];
    }
}

#[StopOnFirstFailure]
#[ErrorBag('attribute-bag')]
#[RedirectTo('/attribute-redirect')]
#[RedirectToRoute('missing-attribute-route')]
class MethodConfiguredFailureDataFixture extends Data
{
    public function __construct(
        #[StringType]
        public string $first,
        #[StringType]
        public string $second,
    ) {
    }

    /**
     * Determine whether validation stops after the first failure.
     */
    public static function stopOnFirstFailure(): bool
    {
        return false;
    }

    /**
     * Get the validation failure redirect URL.
     */
    public static function redirect(ValidationLifecycleDependency $dependency): string
    {
        return $dependency->redirect;
    }

    /**
     * Get the validation failure redirect route.
     */
    public static function redirectRoute(): string
    {
        return 'missing-method-route';
    }

    /**
     * Get the validation failure error bag.
     */
    public static function errorBag(ValidationLifecycleDependency $dependency): string
    {
        return $dependency->errorBag;
    }
}

#[StopOnFirstFailure]
#[ErrorBag('attribute-bag')]
#[RedirectToRoute('attribute-redirect')]
class AttributeConfiguredFailureDataFixture extends Data
{
    public function __construct(
        #[StringType]
        public string $first,
        #[StringType]
        public string $second,
    ) {
    }
}

class NullDependentValidationDataFixture extends Data
{
    public function __construct(
        #[RequiredUnless('status', null)]
        public string|Optional $name,
        public mixed $status = null,
    ) {
    }
}

class PrecognitiveValidatedDataFixture extends Data
{
    public static int $constructorCalls = 0;

    public function __construct(
        public string $value,
    ) {
        ++self::$constructorCalls;
    }
}

class PrecognitiveAfterCallbackDataFixture extends Data
{
    public function __construct(
        public string $value,
    ) {
    }

    /**
     * Get root Validator after callbacks.
     */
    public static function after(): array
    {
        return [static function (Validator $validator): void {
            $validator->errors()->add('value', 'Rejected by the after callback.');
        }];
    }
}

class PrecognitiveDistinctItemDataFixture extends Data
{
    public function __construct(
        #[MapInputName('item_code')]
        #[Distinct]
        public string $itemCode,
    ) {
    }
}

class PrecognitiveDistinctDataFixture extends Data
{
    /**
     * Create a Precognition wildcard identity fixture.
     *
     * @param array<array-key, PrecognitiveDistinctItemDataFixture> $items
     */
    public function __construct(
        #[DataCollectionOf(PrecognitiveDistinctItemDataFixture::class)]
        public array $items,
    ) {
    }
}

#[FailOnUnknownFields]
class PrecognitiveStrictDataFixture extends Data
{
    public function __construct(
        public string $name,
        public string $email,
    ) {
    }
}
