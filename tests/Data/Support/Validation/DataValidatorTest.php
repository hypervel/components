<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation;

use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Response as AuthorizationResponse;
use Hypervel\Container\Attributes\Config;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MergeValidationRules;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Attributes\Validation\Distinct;
use Hypervel\Data\Attributes\Validation\Required;
use Hypervel\Data\Attributes\Validation\RequiredUnless;
use Hypervel\Data\Attributes\Validation\StringType;
use Hypervel\Data\Attributes\WithoutValidation;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Optional;
use Hypervel\Data\Resource;
use Hypervel\Data\Support\DataProperty;
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
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\Factory as ValidationFactory;
use Hypervel\Validation\ValidationException;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        $arrayData = ValidatedDataFixture::from(['id' => 'invalid']);

        $this->assertSame(0, $arrayData->id);

        $this->expectException(ValidationException::class);

        ValidatedDataFixture::from(Request::create('/', 'POST', ['id' => 'invalid']));
    }

    /**
     * Test all three base classes share the request-only validation default.
     */
    public function testBaseClassesShareRequestOnlyValidationByDefault(): void
    {
        $this->assertSame(0, ValidatedDtoFixture::from(['id' => 'invalid'])->id);
        $this->assertSame(0, ValidatedResourceFixture::from(['id' => 'invalid'])->id);

        foreach ([ValidatedDtoFixture::class, ValidatedResourceFixture::class] as $class) {
            try {
                $class::from(Request::create('/', 'POST', ['id' => 'invalid']));
                $this->fail("Expected {$class} Request validation to fail.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('id', $exception->errors());
            }
        }
    }

    /**
     * Test a factory can validate non-Request payloads.
     */
    public function testFactoryCanAlwaysValidateArrayPayloads(): void
    {
        $this->expectException(ValidationException::class);

        ValidatedDataFixture::factory()
            ->alwaysValidate()
            ->from(['id' => 'invalid']);
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
     * Test inferred rules follow the declared presence and primitive types.
     */
    public function testExposesInferredValidationRules(): void
    {
        $rules = ValidatedDataFixture::getValidationRules(['id' => 1]);

        $this->assertSame(['required', 'integer'], $rules['id']);
        $this->assertSame(['nullable', 'string'], $rules['nickname']);
        $this->assertSame(['sometimes', 'string'], $rules['note']);
        $this->assertSame(['string'], $rules['label']);
    }

    /**
     * Test mixed collection wire choices produce exact mapped error paths.
     */
    public function testValidatesNestedDataCollectionsWithMappedWireKeys(): void
    {
        try {
            ValidatedParentDataFixture::factory()
                ->alwaysValidate()
                ->from([
                    'children' => [
                        ['profile' => ['name' => 123]],
                        ['name' => 456],
                    ],
                ]);
            $this->fail('Expected nested validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('children.0.profile.name', $exception->errors());
            $this->assertArrayHasKey('children.1.name', $exception->errors());
        }
    }

    /**
     * Test validation materializes lazy data items into an array declaration.
     */
    public function testValidationMaterializesLazyCollectionForArrayProperty(): void
    {
        $data = ValidatedParentDataFixture::validateAndCreate([
            'children' => LazyCollection::make([
                ['name' => 'Taylor'],
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
                ['name' => 'Taylor'],
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
            ['name' => 'Taylor'],
            ['name' => 'Abigail'],
        ];
        $arrayRules = ValidatedLazyParentDataFixture::getValidationRules([
            'children' => $children,
        ]);
        $lazyRules = ValidatedLazyParentDataFixture::getValidationRules([
            'children' => LazyCollection::make($children),
        ]);

        $this->assertSame($arrayRules, $lazyRules);
        $this->assertArrayHasKey('children.*.name', $lazyRules);
    }

    /**
     * Test class wildcard rules follow each observed collection wire path.
     */
    public function testTranslatesClassWildcardRulesAcrossMixedWireKeys(): void
    {
        try {
            ValidatedParentDataFixture::validateAndCreate([
                'children' => [
                    ['profile' => ['name' => 'one']],
                    ['name' => 'two'],
                ],
            ]);
            $this->fail('Expected nested class rules to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('children.0.profile.name', $exception->errors());
            $this->assertArrayHasKey('children.1.name', $exception->errors());
        }
    }

    /**
     * Test uniform static collections compile one wildcard rule template.
     */
    public function testUniformStaticCollectionUsesWildcardRules(): void
    {
        $rules = ValidatedParentDataFixture::getValidationRules([
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
        $this->expectExceptionMessage(
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
        $this->expectExceptionMessage(
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
        $this->expectExceptionMessage(
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
                    ['finished' => true, 'name' => 'finished'],
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
     * Test nested messages and labels follow each selected wire path.
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
                    ['name' => 456],
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
                $exception->errors()['children.1.name'],
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
                    'The items.1.item_code field has a duplicate value.',
                ],
            ], $exception->errors());
        }
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
     * Finish selected payloads before validation.
     */
    public static function fromPayload(array $payload): static|array
    {
        return ($payload['finished'] ?? false) === true
            ? new static($payload['name'])
            : $payload;
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
