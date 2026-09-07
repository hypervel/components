<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Creation;

use Attribute;
use Closure;
use DateTime;
use DateTimeImmutable;
use Hypervel\Container\Attributes\Config;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\AutoClosureLazy;
use Hypervel\Data\Attributes\AutoInertiaDeferred;
use Hypervel\Data\Attributes\AutoInertiaLazy;
use Hypervel\Data\Attributes\AutoLazy;
use Hypervel\Data\Attributes\AutoWhenLoadedLazy;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Casts\Castable;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Enums\DataPropertyOperation;
use Hypervel\Data\Exceptions\CannotCreateAbstractClass;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Data\Exceptions\CannotCreateDataCollectable;
use Hypervel\Data\Exceptions\CannotSetComputedValue;
use Hypervel\Data\Lazy;
use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Optional;
use Hypervel\Data\Resource;
use Hypervel\Data\Support\Creation\AutoLazyReplayMode;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\Creation\CreationContextFactory;
use Hypervel\Data\Support\Creation\CreationMode;
use Hypervel\Data\Support\Creation\DataCreator;
use Hypervel\Data\Support\Creation\ValidationStrategy;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionFunction;
use Throwable;
use TypeError;
use WeakReference;

class DataCreatorTest extends TestCase
{
    // REMOVED: Configurable pipeline, prepareForPipeline(), and inherited-context factory tests; use the fixed engine and fresh factory hooks.
    // REMOVED: withOptionalValues()/withoutOptionalValues() tests; Optional declarations always preserve absence.
    // REMOVED: Data-specific From* injection tests; Hypervel contextual attributes cover the same outcomes directly.
    // REMOVED: UnserializeCast tests; serialized request input is not accepted by a built-in cast.

    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCreatesMappedDataWithCastsAndFixedAbsenceSemantics(): void
    {
        $data = BasicCreationData::from([
            'name' => 'fallback',
            'profile' => ['name' => 'Taylor'],
            'age' => '21',
        ]);

        $this->assertSame('Taylor', $data->name);
        $this->assertSame(21, $data->age);
        $this->assertNull($data->nickname);
        $this->assertInstanceOf(Optional::class, $data->note);
    }

    public function testFirstSourceContainingAPropertyWinsAndMappingCanBeDisabled(): void
    {
        $first = BasicCreationData::from(
            ['name' => 'First'],
            ['profile' => ['name' => 'Second']],
        );
        $unmapped = BasicCreationData::factory()
            ->withoutPropertyNameMapping()
            ->from([
                'name' => 'Plain',
                'profile' => ['name' => 'Mapped'],
            ]);

        $this->assertSame('First', $first->name);
        $this->assertSame('Plain', $unmapped->name);
        $this->assertNotSame(BasicCreationData::factory(), BasicCreationData::factory());
    }

    public function testFreshDefaultFactoriesShareOnlyTheirImmutableContext(): void
    {
        $first = BasicCreationData::factory();
        $second = BasicCreationData::factory();
        $other = ChildCreationData::factory();

        $this->assertNotSame($first, $second);
        $this->assertSame($first->get(), $second->get());
        $this->assertNotSame($first->get(), $other->get());

        $default = $first->get();
        $first->withoutPropertyNameMapping();

        $this->assertNotSame($default, $first->get());
        $this->assertSame($default, BasicCreationData::factory()->get());
    }

    public function testEveryFactoryMutatorInvalidatesAndRebuildsTheCreateContext(): void
    {
        $hook = static fn (mixed $value): mixed => $value;
        $cases = [
            'validationStrategy' => [
                static fn (CreationContextFactory $factory) => $factory->validationStrategy(ValidationStrategy::Disabled),
                fn (CreationContext $context) => $this->assertSame(ValidationStrategy::Disabled, $context->validationStrategy),
            ],
            'withoutValidation' => [
                static fn (CreationContextFactory $factory) => $factory->withoutValidation(),
                fn (CreationContext $context) => $this->assertSame(ValidationStrategy::Disabled, $context->validationStrategy),
            ],
            'onlyValidateRequests' => [
                static fn (CreationContextFactory $factory) => $factory->onlyValidateRequests(),
                fn (CreationContext $context) => $this->assertSame(ValidationStrategy::OnlyRequests, $context->validationStrategy),
            ],
            'alwaysValidate' => [
                static fn (CreationContextFactory $factory) => $factory->alwaysValidate(),
                fn (CreationContext $context) => $this->assertSame(ValidationStrategy::Always, $context->validationStrategy),
            ],
            'withPropertyNameMapping' => [
                static fn (CreationContextFactory $factory) => $factory->withPropertyNameMapping(),
                fn (CreationContext $context) => $this->assertTrue($context->mapPropertyNames),
            ],
            'withoutPropertyNameMapping' => [
                static fn (CreationContextFactory $factory) => $factory->withoutPropertyNameMapping(),
                fn (CreationContext $context) => $this->assertFalse($context->mapPropertyNames),
            ],
            'withoutMagicalCreation' => [
                static fn (CreationContextFactory $factory) => $factory->withoutMagicalCreation(),
                fn (CreationContext $context) => $this->assertTrue($context->disableMagicalCreation),
            ],
            'withMagicalCreation' => [
                static fn (CreationContextFactory $factory) => $factory->withMagicalCreation(),
                fn (CreationContext $context) => $this->assertFalse($context->disableMagicalCreation),
            ],
            'ignoreMagicalMethod' => [
                static fn (CreationContextFactory $factory) => $factory->ignoreMagicalMethod('fromString'),
                fn (CreationContext $context) => $this->assertSame(['fromString'], $context->ignoredMagicalMethods),
            ],
            'withCast' => [
                static fn (CreationContextFactory $factory) => $factory->withCast(CreationSource::class, CreationIdentifierCast::class),
                fn (CreationContext $context) => $this->assertSame(
                    [CreationSource::class => CreationIdentifierCast::class],
                    $context->casts,
                ),
            ],
            'withCastCollection' => [
                static fn (CreationContextFactory $factory) => $factory->withCastCollection([
                    CreationSource::class => CreationIdentifierCast::class,
                ]),
                fn (CreationContext $context) => $this->assertSame(
                    [CreationSource::class => CreationIdentifierCast::class],
                    $context->casts,
                ),
            ],
            'withNormalizers' => [
                static fn (CreationContextFactory $factory) => $factory->withNormalizers(CreationSourceNormalizer::class),
                fn (CreationContext $context) => $this->assertSame(
                    [CreationSourceNormalizer::class],
                    $context->normalizers,
                ),
            ],
            'prepareData' => [
                static fn (CreationContextFactory $factory) => $factory->prepareData($hook),
                fn (CreationContext $context) => $this->assertSame([$hook], $context->prepareDataHooks),
            ],
            'beforeValidation' => [
                static fn (CreationContextFactory $factory) => $factory->beforeValidation($hook),
                fn (CreationContext $context) => $this->assertSame([$hook], $context->beforeValidationHooks),
            ],
            'beforeRules' => [
                static fn (CreationContextFactory $factory) => $factory->beforeRules($hook),
                fn (CreationContext $context) => $this->assertSame([$hook], $context->beforeRulesHooks),
            ],
            'afterRules' => [
                static fn (CreationContextFactory $factory) => $factory->afterRules($hook),
                fn (CreationContext $context) => $this->assertSame([$hook], $context->afterRulesHooks),
            ],
            'withValidator' => [
                static fn (CreationContextFactory $factory) => $factory->withValidator($hook),
                fn (CreationContext $context) => $this->assertSame([$hook], $context->withValidatorHooks),
            ],
            'afterValidation' => [
                static fn (CreationContextFactory $factory) => $factory->afterValidation($hook),
                fn (CreationContext $context) => $this->assertSame([$hook], $context->afterValidationHooks),
            ],
            'beforeCreation' => [
                static fn (CreationContextFactory $factory) => $factory->beforeCreation($hook),
                fn (CreationContext $context) => $this->assertSame([$hook], $context->beforeCreationHooks),
            ],
            'afterCreation' => [
                static fn (CreationContextFactory $factory) => $factory->afterCreation($hook),
                fn (CreationContext $context) => $this->assertSame([$hook], $context->afterCreationHooks),
            ],
        ];

        foreach ($cases as $name => [$mutate, $verify]) {
            $factory = BasicCreationData::factory();
            $before = $factory->get();

            $this->assertSame($before, $factory->get(), $name);
            $mutate($factory);
            $after = $factory->get();

            $this->assertNotSame($before, $after, $name);
            $this->assertSame($after, $factory->get(), $name);
            $verify($after);
        }
    }

    public function testFromRetainsTheLateStaticFactoryBoundary(): void
    {
        FactoryOverrideCreationData::$factoryCalls = 0;

        $data = FactoryOverrideCreationData::from([
            'name' => 'Raw',
            'profile' => ['name' => 'Mapped'],
        ]);

        $this->assertSame('Raw', $data->name);
        $this->assertSame(1, FactoryOverrideCreationData::$factoryCalls);
    }

    /**
     * Test exact array creation preserves mapping, absence, and accepted values.
     */
    public function testDirectArrayCreationPreservesExactValues(): void
    {
        $child = new ChildCreationData(42);
        $date = new DateTimeImmutable('2026-09-02T12:00:00+00:00');
        $source = new CreationSource('source', 'identifier');
        $data = DirectArrayCreationData::from([
            'profile' => ['name' => 'Mapped'],
            'name' => 'Fallback',
            'nullable_value' => null,
            'defaultedNullable' => null,
            'metadata' => ['role' => 'maintainer'],
            'child' => $child,
            'date' => $date,
            'status' => CreationStatus::Active,
            'source' => $source,
            'assigned' => 'assigned',
        ]);
        $fallback = DirectArrayCreationData::from([
            'name' => 'Fallback',
            'nullable' => 'raw-fallback',
            'child' => $child,
            'date' => $date,
            'status' => CreationStatus::Inactive,
            'source' => $source,
            'assigned' => 'fallback-assigned',
        ]);

        $this->assertSame('Mapped', $data->name);
        $this->assertNull($data->nullable);
        $this->assertInstanceOf(Optional::class, $data->optional);
        $this->assertNull($data->defaultedNullable);
        $this->assertSame(21, $data->defaultedInteger);
        $this->assertSame(['role' => 'maintainer'], $data->metadata);
        $this->assertSame($child, $data->child);
        $this->assertSame($date, $data->date);
        $this->assertSame(CreationStatus::Active, $data->status);
        $this->assertSame($source, $data->source);
        $this->assertSame('assigned', $data->assigned);
        $this->assertSame('unbound-default', $data->unboundDefault);
        $this->assertSame('computed', $data->computed);
        $this->assertSame('virtual', $data->virtual);
        $this->assertSame('Fallback', $fallback->name);
        $this->assertSame('raw-fallback', $fallback->nullable);
        $this->assertSame('fallback', $fallback->defaultedNullable);
        $this->assertSame([], $fallback->metadata);
    }

    /**
     * Test direct array misses retain the authoritative general construction path.
     */
    public function testLeanCreationMatchesGeneralCreationForNestedAndConvertedValues(): void
    {
        $nestedPayload = ['child' => ['id' => '42']];
        $convertedPayload = [
            'id' => '7',
            'date' => '2026-09-02T12:00:00+00:00',
            'status' => 'active',
            'integerStatus' => '1',
        ];
        $nested = DirectNestedCreationData::from($nestedPayload);
        $generalNested = DirectNestedCreationData::factory()
            ->beforeCreation(static fn (array $properties): array => $properties)
            ->from($nestedPayload);
        $converted = DirectConvertedCreationData::from($convertedPayload);
        $generalConverted = DirectConvertedCreationData::factory()
            ->beforeCreation(static fn (array $properties): array => $properties)
            ->from($convertedPayload);
        $items = DirectNestedCreationData::collect([
            ['child' => new ChildCreationData(8)],
        ], 'array');

        $this->assertSame(42, $nested->child->id);
        $this->assertSame($nested->child->id, $generalNested->child->id);
        $this->assertSame(7, $converted->id);
        $this->assertSame($converted->id, $generalConverted->id);
        $this->assertInstanceOf(DateTimeImmutable::class, $converted->date);
        $this->assertEquals($converted->date, $generalConverted->date);
        $this->assertSame(CreationStatus::Active, $converted->status);
        $this->assertSame($converted->status, $generalConverted->status);
        $this->assertSame(IntegerCreationStatus::Active, $converted->integerStatus);
        $this->assertSame($converted->integerStatus, $generalConverted->integerStatus);
        $this->assertSame(8, $items[0]->child->id);
    }

    /**
     * Test lean construction coerces numeric strings to integer-backed enums.
     */
    public function testLeanCreationCoercesNumericStringsToIntegerBackedEnums(): void
    {
        $property = $this->app->make(DataClassRepository::class)
            ->get(DirectConvertedCreationData::class)
            ->properties['integerStatus'];

        $this->assertSame(DataPropertyOperation::Enum, $property->constructionOperation);
        $this->assertSame(IntegerCreationStatus::class, $property->constructionTarget);

        $data = DirectConvertedCreationData::from([
            'id' => 7,
            'date' => new DateTimeImmutable,
            'status' => CreationStatus::Active,
            'integerStatus' => '1',
        ]);

        $this->assertSame(IntegerCreationStatus::Active, $data->integerStatus);
    }

    /**
     * Test lean construction preserves higher-priority conversion behavior.
     *
     * @param class-string<Data> $class
     */
    #[DataProvider('leanConstructionPriorityProvider')]
    public function testLeanConstructionPreservesHigherPriorityConversionBehavior(
        string $class,
        mixed $value,
    ): void {
        $property = $this->app->make(DataClassRepository::class)->get($class)->properties['value'];

        $this->assertSame(DataPropertyOperation::Copy, $property->constructionOperation);
        $this->assertNull($property->constructionTarget);

        $lean = $this->captureCreationOutcome(
            static fn (): mixed => $class::from(['value' => $value])->value,
        );
        $general = $this->captureCreationOutcome(
            static fn (): mixed => $class::factory()
                ->beforeCreation(static fn (array $properties): array => $properties)
                ->from(['value' => $value])
                ->value,
        );

        $this->assertEquals($general, $lean);
    }

    /**
     * Provide order-sensitive construction declarations.
     *
     * @return array<string, array{class-string<Data>, mixed}>
     */
    public static function leanConstructionPriorityProvider(): array
    {
        return [
            'ambiguous Data before date' => [AmbiguousDataBeforeDateCreationData::class, '2026-01-01'],
            'Castable before date' => [CastableBeforeDateCreationData::class, '2026-01-01'],
            'ambiguous date before enum' => [AmbiguousDateBeforeEnumCreationData::class, '2026-01-01'],
            'ambiguous enum before built-in' => [AmbiguousEnumBeforeBuiltinCreationData::class, '1'],
        ];
    }

    public function testLeanCreationPreflightsBeforeRunningNestedConstruction(): void
    {
        PreflightChildCreationData::$constructorCalls = 0;

        try {
            PreflightParentCreationData::from([
                'child' => ['id' => '9'],
                'source' => 'unsupported',
            ]);
            $this->fail('Expected the unsupported object value to be rejected.');
        } catch (TypeError) {
            $this->assertSame(1, PreflightChildCreationData::$constructorCalls);
        }
    }

    public function testLeanParentSharesResolvedExtensionsAcrossGeneralChildren(): void
    {
        DeferredItemCreationCast::$instances = 0;

        $data = LeanParentWithGeneralChildrenData::from([
            'first' => ['id' => '17'],
            'second' => ['id' => '18'],
        ]);

        $this->assertSame(17, $data->first->id);
        $this->assertSame(18, $data->second->id);
        $this->assertSame(1, DeferredItemCreationCast::$instances);
    }

    /**
     * Test computed and virtual input keeps the existing rejection behavior.
     */
    public function testDirectArrayCreationRejectsSuppliedComputedAndVirtualValues(): void
    {
        $data = DirectOutputOnlyCreationData::from(['id' => 1]);

        $this->assertSame('computed', $data->computed);
        $this->assertSame('virtual', $data->virtual);

        foreach ([
            ['computed', 'client'],
            ['computed', null],
            ['virtual', 'client'],
            ['virtual', null],
        ] as [$property, $value]) {
            try {
                DirectOutputOnlyCreationData::from(['id' => 1, $property => $value]);
                $this->fail('Expected output-only input to be rejected.');
            } catch (CannotSetComputedValue $exception) {
                $this->assertStringContainsString("\${$property}", $exception->getMessage());
                $this->assertStringContainsString('computed', $exception->getMessage());
            }
        }
    }

    /**
     * Test a named factory is invoked once before an exact array exit.
     */
    public function testDirectArrayCreationDoesNotRematchNamedFactories(): void
    {
        DirectNamedFactoryCreationData::$calls = 0;

        $data = DirectNamedFactoryCreationData::from('9');

        $this->assertSame(9, $data->id);
        $this->assertSame(1, DirectNamedFactoryCreationData::$calls);
    }

    /**
     * Test the direct exit cannot replace an array-returning engine mode.
     */
    public function testDirectArrayCreationIsCreateModeOnly(): void
    {
        $creator = $this->app->make(DataCreator::class);
        $payload = ['id' => 11];
        $context = new CreationContext(
            dataClass: DirectValidationModeCreationData::class,
            mode: CreationMode::Validate,
            validationStrategy: ValidationStrategy::Disabled,
        );

        $this->assertSame($payload, $creator->validate(
            DirectValidationModeCreationData::class,
            $context,
            [$payload],
        ));
    }

    /**
     * Test the direct exit uses the shared constructor visibility error.
     */
    public function testDirectArrayCreationUsesTheSharedInstantiator(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('constructor is private');

        DirectPrivateConstructorCreationData::from(['value' => 'private']);
    }

    /**
     * Test exact array creation rejects a variadic ordinary constructor without nesting its value.
     */
    public function testDirectArrayCreationRejectsVariadicOrdinaryConstructor(): void
    {
        $metadata = $this->app->make(DataClassRepository::class)->get(
            DirectVariadicConstructorCreationData::class,
        );

        $this->assertNotNull($metadata->creationRecipe);
        $this->assertFalse($metadata->directConstructorInstantiation);

        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('::$items] is variadic');
        $this->expectExceptionMessageMatches('/matching public static from\* method/');

        DirectVariadicConstructorCreationData::from([
            'name' => 'Taylor',
            'items' => [1, 2],
        ]);
    }

    /**
     * Test a variadic constructor is rejected before missing parameters are inspected.
     */
    public function testVariadicConstructorErrorPrecedesMissingParameterErrors(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('::$items] is variadic');

        DirectVariadicConstructorCreationData::from(['other' => 'value']);
    }

    /**
     * Test a direct-returning factory can own a non-public variadic constructor.
     */
    public function testNamedFactoryCanOwnNonPublicVariadicConstructor(): void
    {
        $data = DirectPrivateVariadicConstructorCreationData::from(['items' => [1, 2]]);

        $this->assertSame([1, 2], $data->items);
    }

    public function testCreatesNestedDataWithoutReenteringThePublicFactory(): void
    {
        $data = ParentCreationData::from([
            'child' => ['id' => '42'],
        ]);

        $this->assertInstanceOf(ChildCreationData::class, $data->child);
        $this->assertSame(42, $data->child->id);
    }

    public function testCreatesTypedDataIterablesAndPreservesDeclaredContainers(): void
    {
        $data = IterableCreationData::from([
            'children' => [
                ['id' => '1'],
                ['id' => '2'],
            ],
            'collection' => new Collection([
                'first' => ['id' => '3'],
            ]),
        ]);

        $this->assertContainsOnlyInstancesOf(ChildCreationData::class, $data->children);
        $this->assertSame([1, 2], array_column($data->children, 'id'));
        $this->assertInstanceOf(Collection::class, $data->collection);
        $this->assertSame(3, $data->collection->get('first')->id);
    }

    public function testCreatesDeclaredDataCollectionsFromRawItems(): void
    {
        $data = DataCollectionCreationData::from([
            'children' => [
                'first' => ['id' => '7'],
            ],
        ]);

        $this->assertInstanceOf(DataCollection::class, $data->children);
        $this->assertSame(['first'], array_keys($data->children->items()));
        $this->assertSame(7, $data->children['first']->id);
    }

    public function testRebuildsDeclaredDataPaginatorFromRetainedSource(): void
    {
        $source = new Paginator(
            ['first' => ['id' => '7']],
            15,
            2,
            ['path' => '/children', 'query' => ['tenant' => 'one']],
        );

        $data = DataPaginatorCreationData::from(['children' => $source]);

        $this->assertNotSame($source, $data->children);
        $this->assertSame(15, $data->children->perPage());
        $this->assertSame(2, $data->children->currentPage());
        $this->assertSame('/children', $data->children->path());
        $this->assertSame('one', $data->children->getOptions()['query']['tenant']);
        $this->assertSame(7, $data->children->items()['first']->id);
    }

    public function testRebuildsDeclaredScalarPaginatorFromRetainedSource(): void
    {
        $source = new Paginator(['first' => '7'], 15, 2);

        $data = ScalarPaginatorCreationData::from(['ids' => $source]);

        $this->assertNotSame($source, $data->ids);
        $this->assertSame(['first' => 7], $data->ids->items());
        $this->assertSame(2, $data->ids->currentPage());
    }

    public function testValidationHookCanReshapeRetainedPaginatorItems(): void
    {
        $source = new Paginator([['id' => '7']], 15, 2);

        $data = DataPaginatorCreationData::factory()
            ->alwaysValidate()
            ->beforeValidation(static fn (array $payload): array => [
                ...$payload,
                'children' => [['id' => '9']],
            ])
            ->from(['children' => $source]);

        $this->assertSame(9, $data->children->items()[0]->id);
        $this->assertSame(2, $data->children->currentPage());
    }

    public function testPaginatorPropertiesRejectItemOnlySourcesWithoutMetadata(): void
    {
        $this->expectException(CannotCreateDataCollectable::class);
        $this->expectExceptionMessageIsOrContains('from `array`');

        DataPaginatorCreationData::from([
            'children' => [['id' => '7']],
        ]);
    }

    public function testPreservesFinishedDataCollectableAndNativeContainers(): void
    {
        $dataCollection = new DataCollection(ChildCreationData::class, [
            'first' => new ChildCreationData(1),
        ]);
        $collection = new Collection([
            'second' => new ChildCreationData(2),
        ]);

        $data = FinishedCollectionCreationData::validateAndCreate([
            'dataCollection' => $dataCollection,
            'collection' => $collection,
        ]);

        $this->assertSame($dataCollection, $data->dataCollection);
        $this->assertSame($collection, $data->collection);
    }

    public function testPreservesRawCollectionKeysAcrossFillValidationAndConstruction(): void
    {
        $payload = [
            'items' => [
                'tenant.eu' => ['profile' => ['name' => 'Europe']],
                'tenant' => ['name' => 'Global'],
            ],
        ];

        $rules = MappedItemListCreationData::getValidationRules($payload);
        $data = MappedItemListCreationData::validateAndCreate($payload);

        $this->assertArrayHasKey('items.tenant\.eu.profile.name', $rules);
        $this->assertArrayHasKey('items.tenant.name', $rules);
        $this->assertSame(['tenant.eu', 'tenant'], array_keys($data->items));
        $this->assertSame('Europe', $data->items['tenant.eu']->name);
        $this->assertSame('Global', $data->items['tenant']->name);
    }

    public function testPreservesLazyCollectionTraversalWhenValidationIsNotRunning(): void
    {
        $evaluated = false;
        $source = LazyCollection::make(function () use (&$evaluated): iterable {
            $evaluated = true;

            yield ['id' => '5'];
        });

        $data = LazyIterableCreationData::from(['children' => $source]);

        $this->assertFalse($evaluated);
        $this->assertSame(5, $data->children->first()->id);
        $this->assertTrue($evaluated);
    }

    public function testNestedLazyRequestItemsDoNotRestartRootRequestValidation(): void
    {
        LazyRequestItemCreationData::$authorizationCalls = 0;
        $request = Request::create('/', 'POST', ['id' => '5']);
        $data = LazyRequestIterableCreationData::from([
            'children' => LazyCollection::make([$request]),
        ]);

        $this->assertSame(5, $data->children->first()->id);
        $this->assertSame(0, LazyRequestItemCreationData::$authorizationCalls);
    }

    public function testNestedLazyItemsShareAttributeCastsForTheRootOperation(): void
    {
        DeferredItemCreationCast::$instances = 0;
        $data = LazyCastIterableCreationData::from([
            'children' => LazyCollection::make([
                ['id' => '5'],
                ['id' => '7'],
            ]),
        ]);

        $this->assertSame([5, 7], $data->children->pluck('id')->all());
        $this->assertSame(1, DeferredItemCreationCast::$instances);
    }

    public function testAutomaticLazyReplayIsLimitedToStructuralProperties(): void
    {
        RecordingAutoLazy::reset();
        $first = new AutoLazyFirstSource('title');
        $second = new AutoLazySecondSource(['one', 'two'], ['id' => '7']);
        $paginator = new Paginator([['id' => '8']], 15, 2);

        $data = AutoLazyCreationData::from(
            $first,
            $second,
            ['children' => $paginator],
        );

        $this->assertSame($first, RecordingAutoLazy::$payloads['title']);
        $this->assertSame($second, RecordingAutoLazy::$payloads['tags']);
        $this->assertNull(RecordingAutoLazy::$replays['title']);
        $this->assertNull(RecordingAutoLazy::$replays['tags']);
        $this->assertSame(AutoLazyReplayMode::Normal, RecordingAutoLazy::$replays['child']);
        $this->assertSame(AutoLazyReplayMode::Normal, RecordingAutoLazy::$replays['children']);
        $this->assertSame('title', $data->title->resolve());
        $this->assertSame(['one', 'two'], $data->tags->resolve());
        $this->assertSame(7, $data->child->resolve()->id);

        $children = $data->children->resolve();

        $this->assertNotSame($paginator, $children);
        $this->assertSame(2, $children->currentPage());
        $this->assertSame(8, $children->items()[0]->id);

        RecordingAutoLazy::reset();
        AutoLazyNamedFactoryData::$source = null;
        $named = AutoLazyNamedFactoryData::from('named');

        $this->assertSame(AutoLazyNamedFactoryData::$source, RecordingAutoLazy::$payloads['title']);
        $this->assertSame('named', $named->title->resolve());
    }

    public function testAutomaticLazyReplayConstructsExactAndCoercingChildrenOnce(): void
    {
        foreach ([7, '7'] as $id) {
            CountingAutoLazyChildData::$constructorCalls = 0;
            $data = CountingAutoLazyParentData::from(['child' => ['id' => $id]]);

            $this->assertSame(7, $data->child->resolve()->id);
            $this->assertSame(1, CountingAutoLazyChildData::$constructorCalls);
        }
    }

    public function testAutomaticLazyReplayConsumesMappedAndUnmappedStateValues(): void
    {
        $mapped = MappedAutoLazyParentData::from([
            'profile' => ['child' => ['id' => 11]],
        ]);
        $unmapped = MappedAutoLazyParentData::factory()
            ->withoutPropertyNameMapping()
            ->from(['child' => ['id' => 12]]);

        $this->assertSame(11, $mapped->child->resolve()->id);
        $this->assertSame(12, $unmapped->child->resolve()->id);
    }

    public function testAutomaticLazyPaginatorItemsConstructAndRunFactoriesOnce(): void
    {
        CountingAutoLazyChildData::$constructorCalls = 0;
        $source = new Paginator([
            'exact' => ['id' => 13],
            'coercing' => ['id' => '14'],
        ], 15, 2);
        $plain = CountingAutoLazyCollectionData::from(['children' => $source])
            ->children
            ->resolve();

        $this->assertInstanceOf(Paginator::class, $plain);
        $this->assertSame(2, $plain->currentPage());
        $this->assertSame(['exact', 'coercing'], array_keys($plain->items()));
        $this->assertSame([13, 14], array_column($plain->items(), 'id'));
        $this->assertSame(2, CountingAutoLazyChildData::$constructorCalls);

        CountingAutoLazyFactoryChildData::$factoryCalls = 0;
        $factory = CountingAutoLazyFactoryCollectionData::from([
            'children' => new Paginator(['factory' => ['id' => 15]], 15, 3),
        ])->children->resolve();

        $this->assertSame(15, $factory->items()['factory']->id);
        $this->assertSame(1, CountingAutoLazyFactoryChildData::$factoryCalls);
    }

    public function testAutomaticLazyReplayUsesNormalAndHookSpecificFillPaths(): void
    {
        AutoLazyCountingNormalizer::$calls = 0;
        $normal = AutoLazyNormalizedParentData::from([
            'child' => ['id' => '7'],
        ]);

        $this->assertSame(0, AutoLazyCountingNormalizer::$calls);
        $this->assertSame(7, $normal->child->resolve()->id);
        $this->assertSame(1, AutoLazyCountingNormalizer::$calls);

        AutoLazyCountingNormalizer::$calls = 0;
        $hook = AutoLazyNormalizedParentData::factory()
            ->alwaysValidate()
            ->afterValidation(static fn (array $payload): array => [
                ...$payload,
                'child' => ['id' => '9'],
            ])
            ->from(['child' => ['id' => '8']]);

        $this->assertSame(1, AutoLazyCountingNormalizer::$calls);
        $this->assertSame(9, $hook->child->resolve()->id);
        $this->assertSame(1, AutoLazyCountingNormalizer::$calls);
    }

    public function testAutomaticLazyHookReplayReplacesPaginatorSource(): void
    {
        $original = new Paginator([['id' => '7']], 15, 2);
        $replacement = new Paginator([['id' => '9']], 20, 3);
        $data = AutoLazyPaginatorCreationData::factory()
            ->alwaysValidate()
            ->afterValidation(static fn (array $payload): array => [
                ...$payload,
                'children' => $replacement,
            ])
            ->from(['children' => $original]);

        $children = $data->children->resolve();

        $this->assertNotSame($original, $children);
        $this->assertNotSame($replacement, $children);
        $this->assertSame(20, $children->perPage());
        $this->assertSame(3, $children->currentPage());
        $this->assertSame(9, $children->items()[0]->id);
    }

    public function testAutomaticLoadedRelationLazyUsesItsLiveModelSource(): void
    {
        $model = new AutoLazyRelationModel;
        $data = AutoWhenLoadedCreationData::from($model);

        $this->assertInstanceOf(Lazy::class, $data->child);
        $this->assertFalse($data->child->shouldBeIncluded());

        $model->setRelation('child', ['id' => '11']);

        $this->assertTrue($data->child->shouldBeIncluded());
        $this->assertSame(11, $data->child->resolve()->id);

        $nullModel = new AutoLazyRelationModel;
        $nullModel->setRelation('child', null);

        $this->assertNull(AutoWhenLoadedCreationData::from($nullModel)->child);
    }

    public function testAutomaticLoadedRelationLazyConsumesARecipeEligibleChild(): void
    {
        $model = new AutoLazyRelationModel;
        $model->setRelation('child', ['id' => 16]);

        $child = RecipeAutoWhenLoadedCreationData::from($model)->child->resolve();

        $this->assertInstanceOf(CountingAutoLazyChildData::class, $child);
        $this->assertSame(16, $child->id);
    }

    public function testNonReplayAutomaticLazyUsesTheResolvedClosureValue(): void
    {
        $data = NonReplayAutoLazyCreationData::from(['value' => 'filled']);

        $this->assertSame('resolved', $data->value->resolve());
    }

    public function testAutomaticLoadedRelationLazyRequiresAModelSource(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('no Eloquent model source was supplied');

        AutoWhenLoadedCreationData::from([
            'child' => ['id' => '1'],
        ]);
    }

    public function testAutomaticLoadedRelationLazyRejectsAHookSelectedMorphWithoutAModelSource(): void
    {
        $data = AutoLazyMorphParentCreationData::factory()
            ->alwaysValidate()
            ->afterValidation(static fn (array $payload): array => [
                ...$payload,
                'child' => ['type' => 'relation'],
            ])
            ->from(['child' => ['type' => 'plain']]);

        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('no Eloquent model source was supplied');

        $data->child->resolve();
    }

    public function testAutomaticLazyWrapsDefaultsAndPreservesExplicitSentinels(): void
    {
        RecordingAutoLazy::reset();
        $data = AutoLazyDefaultCreationData::from([]);

        $this->assertSame([], RecordingAutoLazy::$payloads['title']);
        $this->assertInstanceOf(Lazy::class, $data->title);
        $this->assertSame('default', $data->title->resolve());
        $this->assertInstanceOf(Lazy::class, $data->child);
        $this->assertSame(12, $data->child->resolve()->id);
        $this->assertNull($data->nullable);
        $this->assertInstanceOf(Optional::class, $data->optional);

        $existing = Lazy::create(static fn (): string => 'existing');
        $supplied = AutoLazyDefaultCreationData::from([
            'title' => $existing,
            'child' => new ChildCreationData(13),
            'nullable' => null,
            'optional' => Optional::create(),
        ]);

        $this->assertSame($existing, $supplied->title);
        $this->assertSame(13, $supplied->child->resolve()->id);
        $this->assertNull($supplied->nullable);
        $this->assertInstanceOf(Optional::class, $supplied->optional);
    }

    public function testAutomaticLazyVariantsDeferTheSameCastPath(): void
    {
        $data = AutoLazyVariantsCreationData::from([
            'closure' => ['id' => '1'],
            'inertia' => ['id' => '2'],
            'deferred' => ['id' => '3'],
        ]);

        $closure = $data->closure->resolve();
        $inertia = $data->inertia->resolve();
        $deferred = $data->deferred->resolve();

        $this->assertSame(1, $closure()->id);
        $this->assertSame(2, $inertia()->id);
        $this->assertSame('analytics', $deferred->group());
        $this->assertTrue($deferred->shouldRescue());
        $this->assertSame(3, $deferred()->id);
    }

    public function testUnresolvedAutomaticLazyStateCanBeSerialized(): void
    {
        $data = AutoLazyNormalizedParentData::from([
            'child' => ['id' => '14'],
        ]);

        $restored = unserialize(serialize($data));

        $this->assertInstanceOf(AutoLazyNormalizedParentData::class, $restored);
        $this->assertInstanceOf(Lazy::class, $restored->child);
        $this->assertSame(14, $restored->child->resolve()->id);
    }

    public function testAutomaticLazySnapshotDoesNotRetainItsOuterSource(): void
    {
        $source = new AutoLazyOuterSource(['id' => '15']);
        $reference = WeakReference::create($source);
        $data = AutoLazyNormalizedParentData::from($source);

        unset($source);
        gc_collect_cycles();

        $this->assertNull($reference->get());
        $this->assertSame(15, $data->child->resolve()->id);
    }

    public function testCastsDeclaredBuiltinEnumAndDateIterableItems(): void
    {
        $data = ScalarIterableCreationData::from([
            'ids' => ['1', '2'],
            'statuses' => ['active', CreationStatus::Inactive],
            'dates' => ['2026-08-30T12:00:00+00:00'],
        ]);

        $this->assertSame([1, 2], $data->ids);
        $this->assertSame([CreationStatus::Active, CreationStatus::Inactive], $data->statuses);
        $this->assertContainsOnlyInstancesOf(DateTimeImmutable::class, $data->dates);
        $this->assertSame('2026-08-30', $data->dates[0]->format('Y-m-d'));
    }

    public function testGenericPhpDocTypesKeepScalarAndIterableCreationSemantics(): void
    {
        $integer = IntegerRangeCreationData::from(['value' => '7']);
        $children = NonEmptyArrayCreationData::from([
            'children' => [['id' => '9']],
        ]);
        $metadata = $this->app
            ->make(DataClassRepository::class)
            ->get(IntegerRangeCreationData::class);

        $this->assertSame(7, $integer->value);
        $this->assertNotNull($metadata->creationRecipe);
        $this->assertContainsOnlyInstancesOf(ChildCreationData::class, $children->children);
        $this->assertSame(9, $children->children[0]->id);
    }

    public function testDtoAndResourceUseTheSameFixedConstructionEngine(): void
    {
        $dto = CreationDto::from(['id' => '1']);
        $resource = CreationResource::from(['id' => '2']);

        $this->assertSame(1, $dto->id);
        $this->assertSame(2, $resource->id);
    }

    public function testNamedFactoriesCanReturnTheTargetOrAnotherNormalizableValue(): void
    {
        $direct = NamedFactoryCreationData::factory()
            ->beforeCreation(fn (): never => throw new CannotCreateData('should not run'))
            ->from('Taylor');
        $continued = NamedFactoryCreationData::factory()
            ->beforeCreation(fn (array $properties): array => [
                ...$properties,
                'value' => strtoupper($properties['value']),
            ])
            ->from(42);

        $this->assertSame('direct:Taylor', $direct->value);
        $this->assertSame('NUMBER:42', $continued->value);
    }

    public function testNamedPayloadMatchesANamedFactory(): void
    {
        $data = NamedFactoryCreationData::from(value: 'Taylor');

        $this->assertSame('direct:Taylor', $data->value);
    }

    public function testNamedPayloadWithoutAFactoryUsesOrdinaryCreation(): void
    {
        $data = ChildCreationData::from(payload: ['id' => '7']);

        $this->assertSame(7, $data->id);
    }

    public function testNamedPayloadRetainsAutomaticLazyFactoryProvenance(): void
    {
        RecordingAutoLazy::reset();
        AutoLazyNamedFactoryData::$source = null;

        $data = AutoLazyNamedFactoryData::from(title: 'named');

        $this->assertSame(AutoLazyNamedFactoryData::$source, RecordingAutoLazy::$payloads['title']);
        $this->assertSame('named', $data->title->resolve());
    }

    public function testNamedPayloadRetainsAutomaticLazySourceWithoutAFactory(): void
    {
        RecordingAutoLazy::reset();
        $payload = ['title' => 'named'];

        $data = AutoLazyNamedPayloadData::from(payload: $payload);

        $this->assertSame($payload, RecordingAutoLazy::$payloads['title']);
        $this->assertSame('named', $data->title->resolve());
    }

    public function testNamedFactoryDependenciesUseContainerCallWithoutMethodBindingInterception(): void
    {
        $this->app->bindMethod(
            [InjectedFactoryCreationData::class, 'fromInjected'],
            fn (): InjectedFactoryCreationData => new InjectedFactoryCreationData('intercepted'),
        );

        $data = InjectedFactoryCreationData::from('payload');

        $this->assertSame(
            'payload:dependency:' . InjectedFactoryCreationData::class,
            $data->value,
        );
    }

    public function testContextualConstructorValuesOverrideClientPayload(): void
    {
        config()->set('app.name', 'Server');

        $data = ContextualCreationData::from([
            'id' => '7',
            'name' => 'Client',
        ]);

        $this->assertSame(7, $data->id);
        $this->assertSame('Server', $data->name);
    }

    public function testResolvesAbstractPropertyMorphsBeforeFillingConcreteProperties(): void
    {
        $shape = ShapeCreationData::from([
            'type' => 'circle',
            'radius' => '12',
        ]);

        $this->assertInstanceOf(CircleCreationData::class, $shape);
        $this->assertSame(12, $shape->radius);
    }

    public function testResolvesMorphsFromBackedEnumsDefaultsAndNestedCollections(): void
    {
        $default = DefaultShapeCreationData::from(['radius' => '3']);
        $mapped = DefaultShapeCreationData::from([
            'status' => 'active',
            'radius' => '4',
        ]);
        $nested = ShapeListCreationData::from([
            'shapes' => [
                [
                    'type' => 'circle',
                    'radius' => '5',
                ],
                [
                    'type' => 'square',
                    'side' => '6',
                ],
            ],
        ]);

        $this->assertInstanceOf(DefaultCircleCreationData::class, $default);
        $this->assertSame(CreationStatus::Active, $default->status);
        $this->assertSame(3, $default->radius);
        $this->assertInstanceOf(DefaultCircleCreationData::class, $mapped);
        $this->assertSame(4, $mapped->radius);
        $this->assertInstanceOf(CircleCreationData::class, $nested->shapes[0]);
        $this->assertSame(5, $nested->shapes[0]->radius);
        $this->assertInstanceOf(SquareCreationData::class, $nested->shapes[1]);
        $this->assertSame(6, $nested->shapes[1]->side);
    }

    public function testResolvesIntegerBackedMorphFromNumericString(): void
    {
        $shape = IntegerShapeCreationData::from([
            'status' => '1',
            'radius' => '7',
        ]);

        $this->assertInstanceOf(IntegerCircleCreationData::class, $shape);
        $this->assertSame(IntegerCreationStatus::Active, $shape->status);
        $this->assertSame(7, $shape->radius);
    }

    public function testRetainsPerItemWireKeyChoices(): void
    {
        $data = MappedItemListCreationData::from([
            'items' => [
                ['profile' => ['name' => 'Mapped']],
                ['name' => 'Plain'],
            ],
        ]);

        $this->assertSame('Mapped', $data->items[0]->name);
        $this->assertSame('Plain', $data->items[1]->name);
    }

    public function testExistingDataItemSubclassesAreFinishedSubtrees(): void
    {
        $prepareCalls = 0;
        $beforeCreationCalls = 0;
        $afterCreationCalls = 0;
        $existing = new ChildCreationDataSubtype(9);

        $data = IterableCreationData::factory()
            ->prepareData(function (array $input) use (&$prepareCalls): array {
                ++$prepareCalls;

                return $input;
            })
            ->beforeCreation(function (array $properties) use (&$beforeCreationCalls): array {
                ++$beforeCreationCalls;

                return $properties;
            })
            ->afterCreation(function (Data $data) use (&$afterCreationCalls): Data {
                ++$afterCreationCalls;

                return $data;
            })
            ->from([
                'children' => [$existing],
                'collection' => [],
            ]);

        $this->assertSame($existing, $data->children[0]);
        $this->assertSame(1, $prepareCalls);
        $this->assertSame(1, $beforeCreationCalls);
        $this->assertSame(1, $afterCreationCalls);
    }

    public function testUnrelatedDataItemsAreNormalizedIntoTheDeclaredItemClass(): void
    {
        $unrelated = new UnrelatedChildCreationData('14');

        $data = IterableCreationData::from([
            'children' => [$unrelated],
            'collection' => [],
        ]);

        $this->assertInstanceOf(ChildCreationData::class, $data->children[0]);
        $this->assertNotSame($unrelated, $data->children[0]);
        $this->assertSame(14, $data->children[0]->id);
    }

    public function testRejectsUnresolvedAndInvalidPropertyMorphs(): void
    {
        foreach (['missing', 'invalid'] as $type) {
            try {
                ShapeCreationData::from(['type' => $type]);
                $this->fail('Expected the abstract data class to be rejected.');
            } catch (CannotCreateAbstractClass $exception) {
                $this->assertStringContainsString(ShapeCreationData::class, $exception->getMessage());
            }
        }
    }

    public function testRejectsAmbiguousDataObjectUnionsWithoutAnExplicitCast(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('ambiguous data-object union');

        AmbiguousCreationData::from(['child' => ['id' => 1]]);
    }

    public function testRejectsAmbiguousDataCollectableUnionsWithoutAnExplicitCast(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('ambiguous data-collectable union');

        AmbiguousDataCollectableCreationData::from([
            'children' => [['id' => 1]],
        ]);
    }

    public function testAcceptsFinishedContainersThroughAnyDataCollectableUnionArm(): void
    {
        $native = new Collection([new ChildCreationData(1)]);
        $package = new DataCollection(
            AlternateChildCreationData::class,
            [new AlternateChildCreationData(2)],
        );

        $nativeData = AmbiguousDataCollectableCreationData::from(['children' => $native]);
        $packageData = AmbiguousDataCollectableCreationData::from(['children' => $package]);

        $this->assertSame($native, $nativeData->children);
        $this->assertSame($package, $packageData->children);
    }

    public function testRejectsFinishedLookingUnionContainerWithTheWrongItems(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('ambiguous data-collectable union');

        AmbiguousDataCollectableCreationData::from([
            'children' => new Collection([new AlternateChildCreationData(1)]),
        ]);
    }

    public function testExplicitCastOwnsAnAmbiguousDataCollectableUnion(): void
    {
        $data = CastedAmbiguousDataCollectableCreationData::from(['children' => '7']);

        $this->assertInstanceOf(Collection::class, $data->children);
        $this->assertInstanceOf(ChildCreationData::class, $data->children->first());
        $this->assertSame(7, $data->children->first()->id);
    }

    public function testUnrelatedUnionArmPassesThroughAmbiguousDataCollectableTypes(): void
    {
        $data = AmbiguousDataCollectableCreationData::from(['children' => 'unchanged']);

        $this->assertSame('unchanged', $data->children);
    }

    public function testClassNormalizersCustomCastsAndCreationHooksShareOneOperation(): void
    {
        $data = CustomizedCreationData::factory()
            ->prepareData(fn (array $input): array => [
                ...$input,
                'label' => $input['label'] . '-prepared',
            ])
            ->afterCreation(function (CustomizedCreationData $data): CustomizedCreationData {
                $data->label = strtoupper($data->label);

                return $data;
            })
            ->from(new CreationSource('item', 'identifier'));

        $this->assertSame(123, $data->id);
        $this->assertSame('CAST:ITEM-PREPARED', $data->label);
    }

    /**
     * Test configured global normalizers participate in construction.
     */
    #[DefineEnvironment('withConfiguredNormalizer')]
    public function testConfiguredGlobalNormalizersParticipateInConstruction(): void
    {
        $data = ConfiguredNormalizerData::from(new CreationSource('item', 'identifier'));

        $this->assertSame('identifier', $data->id);
        $this->assertSame('item', $data->label);
    }

    public function testRejectsSuppliedComputedValuesAndInvalidAfterCreationResults(): void
    {
        try {
            ComputedCreationData::from(['id' => 1, 'summary' => 'client']);
            $this->fail('Expected computed input to be rejected.');
        } catch (CannotSetComputedValue $exception) {
            $this->assertStringContainsString('ComputedCreationData::$summary', $exception->getMessage());
        }

        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('instead of an instance of');

        BasicCreationData::factory()
            ->afterCreation(fn (): ChildCreationData => new ChildCreationData(1))
            ->from(['name' => 'Taylor']);
    }

    /**
     * Capture a creation value or its exact failure contract.
     *
     * @return array{result: 'exception', class: class-string<Throwable>, message: string}|array{result: 'value', value: mixed}
     */
    protected function captureCreationOutcome(Closure $create): array
    {
        try {
            return ['result' => 'value', 'value' => $create()];
        } catch (Throwable $exception) {
            return [
                'result' => 'exception',
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Configure a global data normalizer.
     */
    protected function withConfiguredNormalizer(Application $app): void
    {
        $app->make('config')->set('data.normalizers', [CreationSourceNormalizer::class]);
    }
}

class BasicCreationData extends Data
{
    public function __construct(
        #[MapInputName('profile.name')]
        public string $name,
        public ?string $nickname,
        public string|Optional $note,
        public int $age = 18,
    ) {
    }
}

class FactoryOverrideCreationData extends Data
{
    public static int $factoryCalls = 0;

    public function __construct(
        #[MapInputName('profile.name')]
        public string $name,
    ) {
    }

    public static function factory(): CreationContextFactory
    {
        ++self::$factoryCalls;

        return parent::factory()->withoutPropertyNameMapping();
    }
}

class DirectArrayCreationData extends Data
{
    public string $assigned;

    public string $unboundDefault = 'unbound-default';

    #[Computed]
    public string $computed = 'computed';

    public string $virtual {
        get => 'virtual';
    }

    public function __construct(
        #[MapInputName('profile.name')]
        public string $name,
        #[MapInputName('nullable_value')]
        public ?string $nullable,
        public string|Optional $optional,
        public ChildCreationData $child,
        public DateTimeImmutable $date,
        public CreationStatus $status,
        public CreationSource $source,
        public ?string $defaultedNullable = 'fallback',
        public int $defaultedInteger = 21,
        public array $metadata = [],
    ) {
    }
}

class DirectNestedCreationData extends Data
{
    public function __construct(public ChildCreationData $child)
    {
    }
}

class DirectConvertedCreationData extends Data
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $date,
        public CreationStatus $status,
        public IntegerCreationStatus $integerStatus,
    ) {
    }
}

class PreflightChildCreationData extends Data
{
    public static int $constructorCalls = 0;

    public function __construct(public int $id)
    {
        ++self::$constructorCalls;
    }
}

class PreflightParentCreationData extends Data
{
    public function __construct(
        public PreflightChildCreationData $child,
        public CreationSource $source,
    ) {
    }
}

class GeneralChildCreationData extends Data
{
    public function __construct(
        #[WithCast(DeferredItemCreationCast::class)]
        public int $id,
    ) {
    }
}

class LeanParentWithGeneralChildrenData extends Data
{
    public function __construct(
        public GeneralChildCreationData $first,
        public GeneralChildCreationData $second,
    ) {
    }
}

class DirectOutputOnlyCreationData extends Data
{
    #[Computed]
    public string $computed = 'computed';

    public string $virtual {
        get => 'virtual';
    }

    public function __construct(public int $id)
    {
    }
}

class DirectNamedFactoryCreationData extends Data
{
    public static int $calls = 0;

    public function __construct(public int $id)
    {
    }

    public static function fromString(string $value): array
    {
        ++self::$calls;

        return ['id' => (int) $value];
    }
}

class DirectValidationModeCreationData extends Data
{
    public function __construct(public int $id)
    {
    }
}

class DirectPrivateConstructorCreationData extends Data
{
    public readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }
}

class DirectVariadicConstructorCreationData extends Data
{
    public string $name;

    public array $items;

    public function __construct(string $name, mixed ...$items)
    {
        $this->name = $name;
        $this->items = $items;
    }
}

class DirectPrivateVariadicConstructorCreationData extends Data
{
    public readonly array $items;

    private function __construct(mixed ...$items)
    {
        $this->items = $items;
    }

    public static function fromPayload(array $payload): self
    {
        return new self(...$payload['items']);
    }
}

class ChildCreationData extends Data
{
    public function __construct(
        public int $id,
    ) {
    }
}

class ChildCreationDataSubtype extends ChildCreationData
{
}

class UnrelatedChildCreationData extends Data
{
    public function __construct(
        public string $id,
    ) {
    }
}

class ParentCreationData extends Data
{
    public function __construct(
        public ChildCreationData $child,
    ) {
    }
}

class IterableCreationData extends Data
{
    /**
     * Create an iterable fixture.
     *
     * @param array<array-key, ChildCreationData> $children
     * @param Collection<array-key, ChildCreationData> $collection
     */
    public function __construct(
        #[DataCollectionOf(ChildCreationData::class)]
        public array $children,
        #[DataCollectionOf(ChildCreationData::class)]
        public Collection $collection,
    ) {
    }
}

class LazyIterableCreationData extends Data
{
    /**
     * Create a lazy iterable fixture.
     *
     * @param LazyCollection<array-key, ChildCreationData> $children
     */
    public function __construct(
        #[DataCollectionOf(ChildCreationData::class)]
        public LazyCollection $children,
    ) {
    }
}

class LazyRequestIterableCreationData extends Data
{
    /**
     * Create a lazy Request iterable fixture.
     *
     * @param LazyCollection<array-key, LazyRequestItemCreationData> $children
     */
    public function __construct(
        #[DataCollectionOf(LazyRequestItemCreationData::class)]
        public LazyCollection $children,
    ) {
    }
}

class LazyRequestItemCreationData extends Data
{
    public static int $authorizationCalls = 0;

    public function __construct(
        public int $id,
    ) {
    }

    public static function authorize(): bool
    {
        ++self::$authorizationCalls;

        return false;
    }
}

class LazyCastIterableCreationData extends Data
{
    /**
     * Create a lazy cast iterable fixture.
     *
     * @param LazyCollection<array-key, LazyCastItemCreationData> $children
     */
    public function __construct(
        #[DataCollectionOf(LazyCastItemCreationData::class)]
        public LazyCollection $children,
    ) {
    }
}

class LazyCastItemCreationData extends Data
{
    public function __construct(
        #[WithCast(DeferredItemCreationCast::class)]
        public int $id,
    ) {
    }
}

class DeferredItemCreationCast implements Cast
{
    public static int $instances = 0;

    public function __construct()
    {
        ++self::$instances;
    }

    public function cast(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): int {
        return (int) $value;
    }
}

class AutoLazyCreationData extends Data
{
    /**
     * Create an automatic-lazy fixture.
     *
     * @param Lazy|list<string> $tags
     * @param Lazy|Paginator<array-key, ChildCreationData> $children
     */
    public function __construct(
        #[RecordingAutoLazy]
        public Lazy|string $title,
        #[RecordingAutoLazy]
        public Lazy|array $tags,
        #[RecordingAutoLazy]
        public Lazy|ChildCreationData $child,
        #[RecordingAutoLazy, DataCollectionOf(ChildCreationData::class)]
        public Lazy|Paginator $children,
    ) {
    }
}

class CountingAutoLazyChildData extends Data
{
    public static int $constructorCalls = 0;

    public function __construct(public int $id)
    {
        ++self::$constructorCalls;
    }
}

class CountingAutoLazyParentData extends Data
{
    public function __construct(
        #[AutoLazy]
        public Lazy|CountingAutoLazyChildData $child,
    ) {
    }
}

class MappedAutoLazyParentData extends Data
{
    public function __construct(
        #[AutoLazy, MapInputName('profile.child')]
        public Lazy|CountingAutoLazyChildData $child,
    ) {
    }
}

class CountingAutoLazyCollectionData extends Data
{
    /**
     * Create a counting automatic-lazy collection fixture.
     *
     * @param Lazy|Paginator<array-key, CountingAutoLazyChildData> $children
     */
    public function __construct(
        #[AutoLazy, DataCollectionOf(CountingAutoLazyChildData::class)]
        public Lazy|Paginator $children,
    ) {
    }
}

class CountingAutoLazyFactoryChildData extends Data
{
    public static int $factoryCalls = 0;

    public function __construct(public int $id)
    {
    }

    public static function fromArray(array $payload): self
    {
        ++self::$factoryCalls;

        return new self((int) $payload['id']);
    }
}

class CountingAutoLazyFactoryCollectionData extends Data
{
    /**
     * Create a counting automatic-lazy factory collection fixture.
     *
     * @param Lazy|Paginator<array-key, CountingAutoLazyFactoryChildData> $children
     */
    public function __construct(
        #[AutoLazy, DataCollectionOf(CountingAutoLazyFactoryChildData::class)]
        public Lazy|Paginator $children,
    ) {
    }
}

class AutoLazyFirstSource
{
    public function __construct(
        public readonly string $title,
    ) {
    }
}

class AutoLazySecondSource
{
    /**
     * Create an automatic-lazy source fixture.
     *
     * @param list<string> $tags
     * @param array{id: string} $child
     */
    public function __construct(
        public readonly array $tags,
        public readonly array $child,
    ) {
    }
}

class AutoLazyNamedFactoryData extends Data
{
    public static ?AutoLazyNamedFactorySource $source = null;

    public function __construct(
        #[RecordingAutoLazy]
        public Lazy|string $title,
    ) {
    }

    public static function fromString(string $title): AutoLazyNamedFactorySource
    {
        return self::$source = new AutoLazyNamedFactorySource($title);
    }
}

class AutoLazyNamedPayloadData extends Data
{
    public function __construct(
        #[RecordingAutoLazy]
        public Lazy|string $title,
    ) {
    }
}

class AutoLazyNamedFactorySource
{
    public function __construct(
        public readonly string $title,
    ) {
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class RecordingAutoLazy extends AutoLazy
{
    /** @var array<string, mixed> */
    public static array $payloads = [];

    /** @var array<string, ?AutoLazyReplayMode> */
    public static array $replays = [];

    /**
     * Build an inspectable automatic lazy value.
     */
    public function build(
        Closure $castValue,
        mixed $payload,
        DataProperty $property,
        mixed $value,
    ): Lazy {
        $variables = (new ReflectionFunction($castValue))->getStaticVariables();
        self::$payloads[$property->name] = $payload;
        self::$replays[$property->name] = $variables['replay'] ?? null;

        return parent::build($castValue, $payload, $property, $value);
    }

    /**
     * Reset captured automatic lazy state.
     */
    public static function reset(): void
    {
        self::$payloads = [];
        self::$replays = [];
    }
}

class AutoLazyNormalizedParentData extends Data
{
    public function __construct(
        #[AutoLazy]
        public Lazy|AutoLazyNormalizedChildData $child,
    ) {
    }
}

class AutoLazyOuterSource
{
    /**
     * Create an automatic-lazy outer source fixture.
     *
     * @param array{id: string} $child
     */
    public function __construct(
        public readonly array $child,
    ) {
    }
}

class AutoLazyPaginatorCreationData extends Data
{
    /**
     * Create an automatic-lazy paginator fixture.
     *
     * @param Lazy|Paginator<array-key, ChildCreationData> $children
     */
    public function __construct(
        #[AutoLazy, DataCollectionOf(ChildCreationData::class)]
        public Lazy|Paginator $children,
    ) {
    }
}

class AutoLazyNormalizedChildData extends Data
{
    public function __construct(
        public int $id,
    ) {
    }

    public static function normalizers(): array
    {
        return [AutoLazyCountingNormalizer::class];
    }
}

class AutoLazyCountingNormalizer implements Normalizer
{
    public static int $calls = 0;

    public function normalize(mixed $value): array|Normalized|null
    {
        ++self::$calls;

        return null;
    }
}

class AutoWhenLoadedCreationData extends Data
{
    public function __construct(
        #[AutoWhenLoadedLazy]
        public Lazy|AutoLazyNormalizedChildData|null $child,
    ) {
    }
}

class RecipeAutoWhenLoadedCreationData extends Data
{
    public function __construct(
        #[AutoWhenLoadedLazy]
        public Lazy|CountingAutoLazyChildData|null $child,
    ) {
    }
}

class NonReplayAutoLazyCreationData extends Data
{
    public function __construct(
        #[ResolvedValueAutoLazy]
        public Lazy|string $value,
    ) {
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class ResolvedValueAutoLazy extends AutoLazy
{
    /**
     * Build an automatic lazy value from a distinct resolved input.
     */
    public function build(
        Closure $castValue,
        mixed $payload,
        DataProperty $property,
        mixed $value,
    ): Lazy {
        return Lazy::create(static fn () => $castValue('resolved'));
    }
}

class AutoLazyRelationModel extends Model
{
}

class AutoLazyMorphParentCreationData extends Data
{
    public function __construct(
        #[AutoLazy]
        public Lazy|AutoLazyMorphCreationData $child,
    ) {
    }
}

abstract class AutoLazyMorphCreationData extends Data implements PropertyMorphableData
{
    public function __construct(
        #[PropertyForMorph]
        public string $type,
    ) {
    }

    public static function morph(array $properties): ?string
    {
        return match ($properties['type']) {
            'plain' => AutoLazyPlainMorphCreationData::class,
            'relation' => AutoLazyRelationMorphCreationData::class,
            default => null,
        };
    }
}

class AutoLazyPlainMorphCreationData extends AutoLazyMorphCreationData
{
}

class AutoLazyRelationMorphCreationData extends AutoLazyMorphCreationData
{
    public function __construct(
        string $type,
        #[AutoWhenLoadedLazy]
        public Lazy|AutoLazyNormalizedChildData|null $child = null,
    ) {
        parent::__construct($type);
    }
}

class AutoLazyDefaultCreationData extends Data
{
    public function __construct(
        #[RecordingAutoLazy]
        public Lazy|string $title = 'default',
        #[RecordingAutoLazy]
        public Lazy|ChildCreationData $child = new ChildCreationData(12),
        #[RecordingAutoLazy]
        public Lazy|string|null $nullable = null,
        #[RecordingAutoLazy]
        public Lazy|string|Optional $optional = new Optional,
    ) {
    }
}

class AutoLazyVariantsCreationData extends Data
{
    public function __construct(
        #[AutoClosureLazy]
        public Lazy|ChildCreationData $closure,
        #[AutoInertiaLazy]
        public Lazy|ChildCreationData $inertia,
        #[AutoInertiaDeferred('analytics', rescue: true)]
        public Lazy|ChildCreationData $deferred,
    ) {
    }
}

class FinishedCollectionCreationData extends Data
{
    /**
     * Create a finished-collection fixture.
     *
     * @param Collection<array-key, ChildCreationData> $collection
     */
    public function __construct(
        #[DataCollectionOf(ChildCreationData::class)]
        public DataCollection $dataCollection,
        #[DataCollectionOf(ChildCreationData::class)]
        public Collection $collection,
    ) {
    }
}

class DataCollectionCreationData extends Data
{
    /**
     * Create a data-collection construction fixture.
     *
     * @param DataCollection<array-key, ChildCreationData> $children
     */
    public function __construct(
        #[DataCollectionOf(ChildCreationData::class)]
        public DataCollection $children,
    ) {
    }
}

class DataPaginatorCreationData extends Data
{
    /**
     * Create a paginated data fixture.
     *
     * @param Paginator<array-key, ChildCreationData> $children
     */
    public function __construct(
        #[DataCollectionOf(ChildCreationData::class)]
        public Paginator $children,
    ) {
    }
}

class ScalarPaginatorCreationData extends Data
{
    /**
     * Create a scalar paginator fixture.
     *
     * @param Paginator<array-key, int> $ids
     */
    public function __construct(public Paginator $ids)
    {
    }
}

class ScalarIterableCreationData extends Data
{
    /** @var list<int> */
    public array $ids;

    /** @var list<CreationStatus> */
    public array $statuses;

    /** @var list<DateTimeImmutable> */
    public array $dates;

    public function __construct(array $ids, array $statuses, array $dates)
    {
        $this->ids = $ids;
        $this->statuses = $statuses;
        $this->dates = $dates;
    }
}

class IntegerRangeCreationData extends Data
{
    /**
     * Create an integer-range fixture.
     *
     * @param int<0, max> $value
     */
    public function __construct(public int $value)
    {
    }
}

class NonEmptyArrayCreationData extends Data
{
    /**
     * Create a non-empty array fixture.
     *
     * @param non-empty-array<int, ChildCreationData> $children
     */
    public function __construct(public array $children)
    {
    }
}

enum CreationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum IntegerCreationStatus: int
{
    case Active = 1;
}

class CreationDto extends Dto
{
    public function __construct(
        public int $id,
    ) {
    }
}

class CreationResource extends Resource
{
    public function __construct(
        public int $id,
    ) {
    }
}

class NamedFactoryCreationData extends Data
{
    public function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return new self('direct:' . $value);
    }

    public static function fromNumber(int $value): array
    {
        return ['value' => 'number:' . $value];
    }
}

class InjectedFactoryCreationData extends Data
{
    public function __construct(
        public string $value,
    ) {
    }

    public static function fromInjected(
        string $value,
        NamedFactoryCreationDependency $dependency,
        CreationContext $context,
    ): self {
        return new self($value . ':' . $dependency->value . ':' . $context->dataClass);
    }
}

class NamedFactoryCreationDependency
{
    public string $value = 'dependency';
}

class ContextualCreationData extends Data
{
    public function __construct(
        #[Config('app.name')]
        public string $name,
        public int $id,
    ) {
    }
}

abstract class ShapeCreationData extends Data implements PropertyMorphableData
{
    public function __construct(
        #[PropertyForMorph]
        public string $type,
    ) {
    }

    public static function morph(array $properties): ?string
    {
        return match ($properties['type']) {
            'circle' => CircleCreationData::class,
            'square' => SquareCreationData::class,
            'invalid' => ChildCreationData::class,
            default => null,
        };
    }
}

class CircleCreationData extends ShapeCreationData
{
    public function __construct(string $type, public int $radius)
    {
        parent::__construct($type);
    }
}

class SquareCreationData extends ShapeCreationData
{
    public function __construct(string $type, public int $side)
    {
        parent::__construct($type);
    }
}

abstract class DefaultShapeCreationData extends Data implements PropertyMorphableData
{
    public function __construct(
        #[PropertyForMorph]
        public CreationStatus $status = CreationStatus::Active,
    ) {
    }

    public static function morph(array $properties): ?string
    {
        return match ($properties['status']) {
            CreationStatus::Active => DefaultCircleCreationData::class,
            default => null,
        };
    }
}

class DefaultCircleCreationData extends DefaultShapeCreationData
{
    public function __construct(
        public int $radius,
        CreationStatus $status = CreationStatus::Active,
    ) {
        parent::__construct($status);
    }
}

abstract class IntegerShapeCreationData extends Data implements PropertyMorphableData
{
    public function __construct(
        #[PropertyForMorph]
        public IntegerCreationStatus $status,
    ) {
    }

    public static function morph(array $properties): ?string
    {
        return match ($properties['status']) {
            IntegerCreationStatus::Active => IntegerCircleCreationData::class,
        };
    }
}

class IntegerCircleCreationData extends IntegerShapeCreationData
{
    public function __construct(IntegerCreationStatus $status, public int $radius)
    {
        parent::__construct($status);
    }
}

class ShapeListCreationData extends Data
{
    /**
     * Create a shape-list fixture.
     *
     * @param array<array-key, ShapeCreationData> $shapes
     */
    public function __construct(
        #[DataCollectionOf(ShapeCreationData::class)]
        public array $shapes,
    ) {
    }
}

class MappedItemCreationData extends Data
{
    public function __construct(
        #[MapInputName('profile.name')]
        public string $name,
    ) {
    }
}

class MappedItemListCreationData extends Data
{
    /**
     * Create a mapped-item list fixture.
     *
     * @param array<array-key, MappedItemCreationData> $items
     */
    public function __construct(
        #[DataCollectionOf(MappedItemCreationData::class)]
        public array $items,
    ) {
    }
}

class AlternateChildCreationData extends Data
{
    public function __construct(
        public int $id,
    ) {
    }
}

class AmbiguousCreationData extends Data
{
    public function __construct(
        public ChildCreationData|AlternateChildCreationData $child,
    ) {
    }
}

class AmbiguousDataBeforeDateCreationData extends Data
{
    public function __construct(
        public ChildCreationData|AlternateChildCreationData|DateTimeImmutable $value,
    ) {
    }
}

class CastableBeforeDateCreationData extends Data
{
    public function __construct(
        public PriorityCreationCastable|DateTimeImmutable $value,
    ) {
    }
}

class AmbiguousDateBeforeEnumCreationData extends Data
{
    public function __construct(
        public DateTimeImmutable|DateTime|PriorityCreationStatus $value,
    ) {
    }
}

class AmbiguousEnumBeforeBuiltinCreationData extends Data
{
    public function __construct(
        public PriorityCreationStatus|AlternatePriorityCreationStatus|int $value,
    ) {
    }
}

class PriorityCreationCastable implements Castable
{
    public function __construct(
        public readonly string $value,
    ) {
    }

    /**
     * Create the cast for this type.
     */
    public static function dataCastUsing(array $arguments): Cast
    {
        return new PriorityCreationCast;
    }
}

class PriorityCreationCast implements Cast
{
    /**
     * Cast a value into the declared Castable type.
     */
    public function cast(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): PriorityCreationCastable {
        return new PriorityCreationCastable((string) $value);
    }
}

enum PriorityCreationStatus: string
{
    case Active = 'active';
}

enum AlternatePriorityCreationStatus: string
{
    case Inactive = 'inactive';
}

class AmbiguousDataCollectableCreationData extends Data
{
    /**
     * Create an ambiguous data-collectable fixture.
     *
     * @param Collection<int, ChildCreationData>|DataCollection<int, AlternateChildCreationData>|string $children
     */
    public function __construct(
        public Collection|DataCollection|string $children,
    ) {
    }
}

class CastedAmbiguousDataCollectableCreationData extends Data
{
    /**
     * Create a cast-owned ambiguous data-collectable fixture.
     *
     * @param Collection<int, ChildCreationData>|DataCollection<int, AlternateChildCreationData> $children
     */
    public function __construct(
        #[WithCast(AmbiguousDataCollectableCreationCast::class)]
        public Collection|DataCollection $children,
    ) {
    }
}

class AmbiguousDataCollectableCreationCast implements Cast
{
    public function cast(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): Collection {
        return new Collection([new ChildCreationData((int) $value)]);
    }
}

class CreationSource
{
    public function __construct(
        public readonly string $label,
        public readonly string $identifier,
    ) {
    }
}

class CustomizedCreationData extends Data
{
    public function __construct(
        #[WithCast(CreationIdentifierCast::class)]
        public int $id,
        #[WithCast(CreationLabelCast::class)]
        public string $label,
    ) {
    }

    public static function normalizers(): array
    {
        return [CreationSourceNormalizer::class];
    }
}

class ConfiguredNormalizerData extends Data
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}

class CreationSourceNormalizer implements Normalizer
{
    public function normalize(mixed $value): array|Normalized|null
    {
        return $value instanceof CreationSource
            ? ['id' => $value->identifier, 'label' => $value->label]
            : null;
    }
}

class CreationIdentifierCast implements Cast
{
    public function cast(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): int {
        return 123;
    }
}

class CreationLabelCast implements Cast
{
    public function cast(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): string {
        return 'cast:' . $value;
    }
}

class ComputedCreationData extends Data
{
    #[Computed]
    public string $summary = 'computed';

    public function __construct(
        public int $id,
    ) {
    }
}
