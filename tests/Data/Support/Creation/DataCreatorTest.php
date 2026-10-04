<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Creation;

use Attribute;
use Closure;
use DateTime;
use DateTimeImmutable;
use Hypervel\Container\Attributes\Config;
use Hypervel\Container\Attributes\RouteParameter;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;
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
use Hypervel\Data\Attributes\Validation\Exclude;
use Hypervel\Data\Attributes\Validation\Min;
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Casts\Castable;
use Hypervel\Data\Casts\IterableItemCast;
use Hypervel\Data\Casts\Uncastable;
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
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Casts\StringToUpperCast;
use Hypervel\Tests\Data\Fixtures\Concerns\BindsRouteParameters;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionFunction;
use RuntimeException;
use Throwable;
use TypeError;
use WeakReference;

class DataCreatorTest extends TestCase
{
    use BindsRouteParameters;

    // REMOVED: Configurable pipeline tests; use the fixed engine and factory hooks.
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

    public function testMalformedNumbersFailWithPhpsTypeErrorWithAndWithoutHooks(): void
    {
        $payload = ['name' => 'Taylor', 'age' => '12abc'];
        $rejected = 0;

        foreach ([
            static fn (): BasicCreationData => BasicCreationData::from($payload),
            static fn (): BasicCreationData => BasicCreationData::factory()
                ->prepareData(static fn (array $data): array => $data)
                ->from($payload),
        ] as $create) {
            try {
                $create();
            } catch (TypeError) {
                ++$rejected;
            }
        }

        $this->assertSame(2, $rejected);
        $this->assertSame(21, BasicCreationData::from([...$payload, 'age' => ' 21 '])->age);
    }

    public function testLaterSourceContainingAPropertyWinsAndMappingCanBeDisabled(): void
    {
        $later = BasicCreationData::from(
            ['name' => 'First', 'nickname' => 'Tay', 'note' => 'kept'],
            ['profile' => ['name' => 'Second'], 'nickname' => null, 'note' => Optional::create()],
        );
        $unmapped = BasicCreationData::factory()
            ->withoutPropertyNameMapping()
            ->from([
                'name' => 'Plain',
                'profile' => ['name' => 'Mapped'],
            ]);

        $this->assertSame('Second', $later->name);
        $this->assertNull($later->nickname);
        $this->assertSame('kept', $later->note);
        $this->assertSame('Plain', $unmapped->name);
        $this->assertNotSame(BasicCreationData::factory(), BasicCreationData::factory());
    }

    public function testAnExplicitOptionalIsKeptWhenNoSourceSuppliesAValue(): void
    {
        $data = OptionalDefaultCreationData::from(['note' => Optional::create()], []);

        $this->assertInstanceOf(Optional::class, $data->note);
    }

    public function testAPrepareDataHookReturningItsInputChangesNothing(): void
    {
        $sources = [
            ['name' => 'First', 'meta' => ['a' => 1]],
            ['name' => 'Second', 'meta' => ['b' => 2]],
        ];

        $plain = PrepareDataIdentityData::from(...$sources);
        $hooked = PrepareDataIdentityData::factory()
            ->prepareData(static fn (array $data): array => $data)
            ->from(...$sources);

        $this->assertSame(['b' => 2], $plain->meta);
        $this->assertSame($plain->toArray(), $hooked->toArray());

        $mappedCases = [
            'php name after a dot mapping' => [
                ['profile' => ['name' => 'old', 'bio' => 'kept'], 'user_code' => 'a'],
                ['name' => 'new'],
            ],
            'dot mapping after a php name' => [
                ['name' => 'old', 'user_code' => 'a'],
                ['profile' => ['name' => 'new']],
            ],
            'php name after a flat mapping' => [
                ['name' => 'Taylor', 'user_code' => 'old'],
                ['code' => 'new'],
            ],
        ];

        foreach ($mappedCases as $case => $mappedSources) {
            $plain = MappedPrepareDataIdentityData::from(...$mappedSources);
            $hooked = MappedPrepareDataIdentityData::factory()
                ->prepareData(static fn (array $data): array => $data)
                ->from(...$mappedSources);

            $this->assertSame($plain->all(), $hooked->all(), $case);
        }

        $received = null;
        MappedPrepareDataIdentityData::factory()
            ->prepareData(static function (array $data) use (&$received): array {
                $received = $data;

                return $data;
            })
            ->from(...$mappedCases['php name after a dot mapping']);

        // The selected value moves to the spelling resolution reads first; sibling input remains.
        $this->assertSame(['name' => 'new', 'bio' => 'kept'], $received['profile']);
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

    public function testNamedFactoriesMustReturnTheRequestedObject(): void
    {
        $direct = NamedFactoryCreationData::factory()
            ->beforeCreation(fn (): never => throw new CannotCreateData('should not run'))
            ->from('Taylor');

        $this->assertSame('direct:Taylor', $direct->value);

        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('the named factory [fromNumber()] returned [array] instead of an instance of');

        NamedFactoryCreationData::from(42);
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

    public function testContextualValuesAreConvertedLikeUnvalidatedInput(): void
    {
        $request = $this->bindRouteParameters([
            'id' => '123',
            'status' => 'foo',
            'at' => '2026-10-04T10:00:00+00:00',
            'child' => ['string' => 'nested'],
            'choice' => ['string' => 'selected'],
            'items' => [['string' => 'first'], ['string' => 'second']],
        ]);

        $data = ContextualConversionCreationData::from($request);

        $this->assertSame(123, $data->id);
        $this->assertSame(DummyBackedEnum::FOO, $data->status);
        $this->assertEquals(new DateTimeImmutable('2026-10-04T10:00:00+00:00'), $data->at);
        $this->assertEquals(new SimpleData('nested'), $data->child);
        $this->assertEquals(new SimpleData('selected'), $data->choice);
        $this->assertEquals([new SimpleData('first'), new SimpleData('second')], $data->items);
        $this->assertNull($data->missing);
    }

    public function testContextualValuesWinOverHooksAndResolveOnceInTheirBuildContext(): void
    {
        config()->set('app.name', 'Server');
        $this->app->bind(ContextualCreationContract::class, ContextualCreationDefault::class);
        $this->app->when(ContextualResolutionCreationData::class)
            ->needs(ContextualCreationContract::class)
            ->give(ContextualCreationForData::class);
        $parameterCallbacks = 0;
        $classCallbacks = 0;
        $this->app->afterResolvingAttribute(
            ContextualCreationDependency::class,
            function () use (&$parameterCallbacks): void {
                ++$parameterCallbacks;
            },
        );
        $this->app->afterResolvingAttribute(
            ContextualCreationMarker::class,
            function () use (&$classCallbacks): void {
                ++$classCallbacks;
            },
        );

        $data = ContextualResolutionCreationData::factory()
            ->beforeCreation(static fn (array $properties): array => [...$properties, 'dependency' => 'hook'])
            ->from(['dependency' => 'client']);

        $this->assertSame(ContextualCreationForData::class, $data->dependency);
        $this->assertSame('Server', $data->label);
        $this->assertSame(1, $parameterCallbacks);
        $this->assertSame(1, $classCallbacks);
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

    public function testRejectsInputNoNormalizerCanRead(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('no normalizer accepted the value');

        ChildCreationData::from(42);
    }

    public function testRejectsAmbiguousDataObjectUnionsWithoutAnExplicitCast(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('ambiguous data-object union');

        AmbiguousCreationData::from(['child' => ['id' => 1]]);
    }

    public function testDataObjectUnionsKeepAcceptedArraysAndBuildOtherValues(): void
    {
        $kept = DataObjectUnionCreationData::validateAndCreate(['child' => ['name' => 'kept']]);
        $built = DataObjectUnionCreationData::validateAndCreate([
            'child' => (object) ['external_name' => 'Taylor'],
            'containers' => (object) ['external_name' => 'Abigail'],
        ]);
        $items = DataObjectUnionListData::validateAndCreate(['items' => [
            ['child' => (object) ['external_name' => 'Taylor']],
            ['child' => ['name' => 'kept']],
        ]]);

        $this->assertSame(['name' => 'kept'], $kept->child);
        $this->assertSame('Taylor', $built->child->name);
        $this->assertSame('Abigail', $built->containers->name);
        $this->assertSame('Taylor', $items->items[0]->child->name);
        $this->assertSame(['name' => 'kept'], $items->items[1]->child);
    }

    public function testRejectsAmbiguousContainerUnionsWithoutAnExplicitCast(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('ambiguous container union');

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

    public function testConvertsItemsOfAContainerOnlyOneUnionTypeAccepts(): void
    {
        $data = AmbiguousDataCollectableCreationData::from([
            'children' => new Collection([new AlternateChildCreationData(1)]),
        ]);

        $this->assertInstanceOf(Collection::class, $data->children);
        $this->assertInstanceOf(ChildCreationData::class, $data->children->first());
        $this->assertSame(1, $data->children->first()->id);
    }

    public function testExplicitCastOwnsAnAmbiguousContainerUnion(): void
    {
        $data = CastedAmbiguousDataCollectableCreationData::from(['children' => '7']);

        $this->assertInstanceOf(Collection::class, $data->children);
        $this->assertInstanceOf(ChildCreationData::class, $data->children->first());
        $this->assertSame(7, $data->children->first()->id);
    }

    public function testTypedContainerUnionsCastItemsInTheContainerTheValueSelects(): void
    {
        $arrays = TypedContainerUnionCreationData::from([
            'both' => ['a' => '1'],
            'shorthand' => ['2'],
            'arrayOnly' => ['3'],
        ]);
        $collections = TypedContainerUnionCreationData::from([
            'both' => new Collection(['a' => '1']),
            'shorthand' => new Collection(['2']),
            'arrayOnly' => new Collection(['3']),
        ]);

        $this->assertSame(['a' => 1], $arrays->both);
        $this->assertSame([2], $arrays->shorthand);
        $this->assertSame([3], $arrays->arrayOnly);
        $this->assertInstanceOf(Collection::class, $collections->both);
        $this->assertSame(['a' => 1], $collections->both->all());
        $this->assertInstanceOf(Collection::class, $collections->shorthand);
        $this->assertSame([2], $collections->shorthand->all());
        $this->assertInstanceOf(Collection::class, $collections->arrayOnly);
        $this->assertSame([3], $collections->arrayOnly->all());
    }

    public function testRejectsValuesNoSingleContainerTypeAccepts(): void
    {
        foreach ([
            static fn (): OverlappingContainerUnionCreationData => OverlappingContainerUnionCreationData::from([
                'values' => new Collection([1]),
            ]),
            static fn (): TypedContainerUnionCreationData => TypedContainerUnionCreationData::from([
                'both' => new LazyCollection(['1']),
                'shorthand' => [],
                'arrayOnly' => [],
            ]),
        ] as $create) {
            try {
                $create();
                $this->fail('Expected an ambiguous container union.');
            } catch (CannotCreateData $exception) {
                $this->assertStringContainsString('ambiguous container union', $exception->getMessage());
            }
        }
    }

    public function testMixedContainerUnionsSelectTheDataOrPlainTypeByContainer(): void
    {
        $plain = MixedContainerUnionCreationData::from(['children' => ['a' => '1', 'b' => '2']]);
        $data = MixedContainerUnionCreationData::from(['children' => new Collection([['id' => '3']])]);

        $this->assertSame(['a' => 1, 'b' => 2], $plain->children);
        $this->assertInstanceOf(Collection::class, $data->children);
        $this->assertInstanceOf(ChildCreationData::class, $data->children->first());
        $this->assertSame(3, $data->children->first()->id);
        $this->assertSame(['children' => ['a' => 1, 'b' => 2]], $plain->toArray());
        $this->assertSame(['children' => [['id' => 3]]], $data->toArray());

        // The plain array type has no item data rules, so its integers pass validation.
        $this->assertSame([1, 2], MixedContainerUnionCreationData::validateAndCreate(['children' => [1, 2]])->children);

        try {
            MixedContainerUnionCreationData::validateAndCreate(['children' => new Collection([['id' => 'x']])]);
            $this->fail('Expected the selected data collection rules to run.');
        } catch (ValidationException $exception) {
            $this->assertSame(['children.0.id'], array_keys($exception->errors()));
        }
    }

    public function testCollectionItemsSelectTheirOwnContainerTypes(): void
    {
        try {
            MixedContainerUnionListData::validateAndCreate(['items' => [
                ['children' => [1, 2]],
                ['children' => new Collection([['id' => 'x']])],
            ]]);
            $this->fail('Expected only the second item to use the data collection rules.');
        } catch (ValidationException $exception) {
            $this->assertSame(['items.1.children.0.id'], array_keys($exception->errors()));
        }

        $data = MixedContainerUnionListData::validateAndCreate(['items' => [
            ['children' => [1, 2]],
            ['children' => new Collection([['id' => '3']])],
        ]]);

        $this->assertSame([1, 2], $data->items[0]->children);
        $this->assertSame(3, $data->items[1]->children->first()->id);
    }

    public function testValidationHooksReselectTheContainerType(): void
    {
        $data = MixedContainerUnionCreationData::factory()
            ->alwaysValidate()
            ->beforeValidation(static fn (array $payload): array => [
                ...$payload,
                'children' => new Collection([['id' => '4']]),
            ])
            ->from(['children' => [1, 2]]);
        $plain = MixedContainerUnionCreationData::factory()
            ->alwaysValidate()
            ->beforeValidation(static fn (array $payload): array => [...$payload, 'children' => ['5']])
            ->from(['children' => new Collection([['id' => 'x']])]);

        $this->assertSame(4, $data->children->first()->id);
        $this->assertSame([5], $plain->children);
    }

    public function testAutomaticLazyContainerUnionsKeepTheirSelection(): void
    {
        $plain = AutoLazyContainerUnionCreationData::from(['children' => ['1']]);
        $data = AutoLazyContainerUnionCreationData::from(['children' => new Collection([['id' => '2']])]);

        $this->assertSame([1], $plain->children->resolve());
        $this->assertSame(2, $data->children->resolve()->first()->id);
    }

    #[DefineEnvironment('withStringToUpperCast')]
    public function testConfiguredItemCastsApplyToTheSelectedContainerType(): void
    {
        $array = ConfiguredItemCastUnionCreationData::from(['names' => ['a']]);
        $collection = ConfiguredItemCastUnionCreationData::from(['names' => new Collection(['b'])]);

        $this->assertSame(['A'], $array->names);
        $this->assertSame(['B'], $collection->names->all());
    }

    public function testUserCastsReceiveTheDeclaredPropertyValues(): void
    {
        RecordingInputsCast::$properties = null;

        $data = CastInputsData::from([
            'first' => '5',
            'recorded' => 'value',
            'later_value' => 'raw later',
            'count' => '7',
        ]);
        $properties = RecordingInputsCast::$properties;

        $this->assertSame(
            ['first', 'recorded', 'later', 'count', 'optional', 'nullable', 'default'],
            array_keys($properties),
        );
        $this->assertSame(5, $properties['first']);
        $this->assertSame('value', $properties['recorded']);
        $this->assertSame('raw later', $properties['later']);
        $this->assertSame('7', $properties['count']);
        $this->assertInstanceOf(Optional::class, $properties['optional']);
        $this->assertNull($properties['nullable']);
        $this->assertSame($data->default, $properties['default']);

        $leading = CastInputsLeadingDefaultData::from(['recorded' => 'value']);

        $this->assertSame($leading->default, RecordingInputsCast::$properties['default']);
    }

    public function testIterableItemCastsShareTheDeclaredPropertyValues(): void
    {
        RecordingItemInputsCast::$received = [];

        $data = CastInputsIterableData::from(['name' => 'Taylor', 'tags' => ['a', 'b']]);

        $this->assertSame(['A', 'B'], $data->tags);
        $this->assertSame(
            array_fill(0, 2, ['name' => 'Taylor', 'tags' => ['a', 'b']]),
            RecordingItemInputsCast::$received,
        );
    }

    public function testDeferredUserCastsSeeTheValuesFromWhenTheyWereDeferred(): void
    {
        RecordingInputsCast::$properties = null;

        $data = CastInputsLazyData::from(['first' => '5', 'recorded' => 'value']);

        $this->assertNull(RecordingInputsCast::$properties);
        $this->assertSame('value', $data->recorded->resolve());
        $this->assertSame(['first' => 5, 'recorded' => 'value'], RecordingInputsCast::$properties);

        RecordingInputsCast::$properties = null;

        $factoryCast = FactoryCastInputsLazyData::factory()
            ->withCast('string', RecordingInputsCast::class)
            ->from(['first' => '5', 'recorded' => 'value']);

        $this->assertSame('value', $factoryCast->recorded->resolve());
        $this->assertSame(['first' => 5, 'recorded' => 'value'], RecordingInputsCast::$properties);
    }

    public function testCastOwnedPropertiesReceiveTheirInputBeforeChildCreation(): void
    {
        // The child's throwing fromString() would run if the child were created before the cast.
        $data = CastOwnedParentData::from(['child' => 'Taylor']);

        $this->assertSame('cast:Taylor', $data->child->name);
    }

    public function testDeclinedCastsBuildChildrenFromValidatedConcreteInput(): void
    {
        CastOwnedChildCast::$received = null;

        $data = CastOwnedParentData::validateAndCreate([
            'child' => ['name' => 'Taylor', 'undeclared' => 'dropped'],
        ]);

        $this->assertSame(['name' => 'Taylor'], CastOwnedChildCast::$received);
        $this->assertSame('Taylor', $data->child->name);

        $shapes = CastOwnedShapesData::validateAndCreate([
            'shape' => ['type' => 'square', 'code' => 'abc'],
            'shapes' => [['type' => 'square', 'code' => 'def']],
        ]);

        $this->assertInstanceOf(CastOwnedSquareData::class, $shapes->shape);
        $this->assertSame('abc', $shapes->shape->code);
        $this->assertInstanceOf(CastOwnedSquareData::class, $shapes->shapes[0]);
        $this->assertSame('def', $shapes->shapes[0]->code);

        try {
            CastOwnedShapesData::validateAndCreate([
                'shape' => ['type' => 'square', 'code' => 'ab'],
                'shapes' => [['type' => 'square', 'code' => 'de']],
            ]);
            $this->fail('Expected the concrete morph rules to run.');
        } catch (ValidationException $exception) {
            $this->assertEqualsCanonicalizing(['shape.code', 'shapes.0.code'], array_keys($exception->errors()));
        }
    }

    public function testValidationNormalizesCastOwnedObjectInput(): void
    {
        CastOwnedChildCast::$received = null;

        $model = CastOwnedParentData::validateAndCreate([
            'child' => (new CastOwnedChildModel)->setRawAttributes(['external_name' => 'Taylor']),
        ]);

        // The cast receives the validated input rather than a child created from the model.
        $this->assertSame(['external_name' => 'Taylor'], CastOwnedChildCast::$received);
        $this->assertSame('Taylor', $model->child->name);

        $nested = CastOwnedWrapperData::validateAndCreate([
            'wrapper' => ['child' => (object) ['external_name' => 'Abigail']],
        ]);

        $this->assertSame(['child' => ['external_name' => 'Abigail']], CastOwnedChildCast::$received);
        $this->assertSame('Abigail', $nested->wrapper->child->name);

        $shapes = CastOwnedShapesData::validateAndCreate([
            'shape' => (object) ['type' => 'square', 'code' => 'abc'],
            'shapes' => new Collection([(object) ['type' => 'square', 'code' => 'def']]),
        ]);

        $this->assertInstanceOf(CastOwnedSquareData::class, $shapes->shape);
        $this->assertSame('abc', $shapes->shape->code);
        $this->assertInstanceOf(CastOwnedSquareData::class, $shapes->shapes[0]);
        $this->assertSame('def', $shapes->shapes[0]->code);

        CountingNameNormalizer::$calls = 0;
        $normalized = CastOwnedNormalizedParentData::validateAndCreate(['child' => 'Taylor']);

        // A string the child's normalizer reads is prepared like an array, and normalized once.
        $this->assertSame('Taylor', $normalized->child->name);
        $this->assertSame(1, CountingNameNormalizer::$calls);

        foreach ([
            // Under validation, input only the cast or a named factory could read fails the declared rules.
            [CastOwnedParentData::class, ['child' => 'Taylor'], ['child']],
            [CastOwnedParentData::class, ['child' => (object) ['other' => 'Taylor']], ['child.external_name']],
            [CastOwnedWrapperData::class, ['wrapper' => ['child' => (object) ['other' => 'Abigail']]], ['wrapper.child.external_name']],
            [CastOwnedShapesData::class, [
                'shape' => (object) ['type' => 'square', 'code' => 'ab'],
                'shapes' => new Collection([(object) ['type' => 'square', 'code' => 'de']]),
            ], ['shape.code', 'shapes.0.code']],
        ] as [$class, $payload, $errors]) {
            CastOwnedChildCast::$received = null;

            try {
                $class::validateAndCreate($payload);
                $this->fail("Expected [{$class}] to fail validation before its cast.");
            } catch (ValidationException $exception) {
                $this->assertEqualsCanonicalizing($errors, array_keys($exception->errors()));
                $this->assertNull(CastOwnedChildCast::$received);
            }
        }
    }

    public function testDeclinedCastsCreateFromThePreparedAndValidatedInput(): void
    {
        $outer = CastOwnedOuterData::validateAndCreate(['inner' => [
            'child' => (object) ['external_name' => 'Taylor'],
            'address' => ['line_1' => '1 Main St', 'city' => 'Chicago'],
        ]]);

        $this->assertInstanceOf(CastOwnedChildData::class, $outer->inner->child);
        $this->assertSame('Taylor', $outer->inner->child->name);
        $this->assertSame('1 Main St, Chicago', $outer->inner->address->address);

        $model = (new CastOwnedChildModel)->setRawAttributes(['name' => 'Taylor']);
        $lazy = CastOwnedLazyParentData::validateAndCreate(['child' => $model]);

        $this->assertFalse($lazy->child->relation->shouldBeIncluded());

        $model->setRelation('relation', ['external_name' => 'Abigail']);

        $this->assertSame('Abigail', $lazy->child->relation->resolve()->name);
    }

    public function testNamedFactoriesBeneathACastRunOnlyAfterItDeclines(): void
    {
        PendingFactoryChildData::$calls = 0;

        $accepted = PendingFactoryAcceptedData::validateAndCreate([
            'wrapper' => ['child' => new PendingFactorySource('Taylor')],
        ]);

        $this->assertSame('cast', $accepted->wrapper->child->name);
        $this->assertSame(0, PendingFactoryChildData::$calls);

        // A factory that could build the child does not exempt its input from validation.
        try {
            PendingFactoryAcceptedData::validateAndCreate(['wrapper' => ['child' => new PendingFactorySource('')]]);
            $this->fail('Expected the child rules to run before the cast.');
        } catch (ValidationException $exception) {
            $this->assertSame(['wrapper.child.name'], array_keys($exception->errors()));
        }

        $declined = PendingFactoryDeclinedData::validateAndCreate(['wrapper' => [
            'child' => new PendingFactorySource('Taylor'),
            'excluded' => new PendingFactorySource('Abigail'),
        ]]);

        $this->assertSame('factory:Taylor', $declined->wrapper->child->name);
        $this->assertNull($declined->wrapper->excluded);
        $this->assertSame(1, PendingFactoryChildData::$calls);

        $hooked = PendingFactoryDeclinedData::factory()
            ->alwaysValidate()
            ->beforeValidation(static fn (array $payload): array => ['wrapper' => ['child' => ['name' => 'Hooked']]])
            ->from(['wrapper' => ['child' => new PendingFactorySource('Taylor')]]);

        // The hook replaced the input the factory matched, so the child is built from the new input.
        $this->assertSame('Hooked', $hooked->wrapper->child->name);
        $this->assertSame(1, PendingFactoryChildData::$calls);
    }

    public function testDeclinedCastsKeepOrdinaryUnionAndIterableConversion(): void
    {
        $kept = CastOwnedUnionData::validateAndCreate(['child' => ['other' => 'kept'], 'numbers' => ['1']]);
        $reads = 0;
        $built = CastOwnedUnionData::from([
            'child' => (object) ['external_name' => 'Taylor'],
            'numbers' => [],
            'children' => LazyCollection::make(function () use (&$reads): iterable {
                ++$reads;

                yield 'first' => ['external_name' => 'Abigail'];
            }),
        ]);

        $this->assertSame(['other' => 'kept'], $kept->child);
        $this->assertSame([1], $kept->numbers);
        $this->assertSame('Taylor', $built->child->name);
        $this->assertSame(0, $reads);
        $this->assertSame('Abigail', $built->children->get('first')->name);
    }

    public function testUnrelatedUnionArmPassesThroughAmbiguousDataCollectableTypes(): void
    {
        $data = AmbiguousDataCollectableCreationData::from(['children' => 'unchanged']);

        $this->assertSame('unchanged', $data->children);
    }

    public function testPrepareForPipelineRunsForEachSource(): void
    {
        PreparedAddressData::$calls = 0;

        PreparedAddressData::from(['name' => 'Taylor'], ['line_1' => '1 Main St']);

        $this->assertSame(2, PreparedAddressData::$calls);
    }

    public function testNestedObjectsArePreparedBeforeValidation(): void
    {
        $data = PreparedOwnerData::validateAndCreate([
            'label' => 'home',
            'owner' => ['line_1' => '1 Main St', 'city' => 'Chicago'],
        ]);

        $this->assertSame('1 Main St, Chicago', $data->owner->address);
    }

    public function testModelSourcesArePreparedAsTheirDeclaredProperties(): void
    {
        $data = PreparedNameData::from((new PreparedNameModel)->forceFill(['name' => 'taylor']));

        $this->assertSame('TAYLOR', $data->name);
    }

    public function testTheMorphedClassPreparesItsPayload(): void
    {
        $shape = PreparedShapeData::from(['type' => 'circle', 'r' => '3']);

        $this->assertInstanceOf(PreparedCircleData::class, $shape);
        $this->assertSame(3, $shape->radius);

        // prepareData hooks run first, so their output selects the class whose method then prepares it.
        $hooked = PreparedShapeData::factory()
            ->prepareData(static fn (array $input): array => [...$input, 'type' => 'circle', 'r' => '4'])
            ->from(['type' => 'square']);

        $this->assertInstanceOf(PreparedCircleData::class, $hooked);
        $this->assertSame(4, $hooked->radius);
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

    public function testConstructorInputsReceiveRawInputByName(): void
    {
        $this->assertSame(
            ['prefix' => 'later', 'options' => ['a' => '1'], 'secret' => 'none'],
            ConstructorInputCreationData::from(['prefix' => 'first', 'options' => ['a' => '1']], ['prefix' => 'later'])->received,
        );

        $hooked = ConstructorInputCreationData::factory()
            ->beforeCreation(static fn (array $properties): array => [...$properties, 'prefix' => 'hooked'])
            ->from(['secret' => 'kept']);

        $this->assertSame(['prefix' => 'hooked', 'options' => [], 'secret' => 'kept'], $hooked->received);

        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('Parameters missing: prefix');

        ConstructorInputCreationData::from([]);
    }

    public function testBeforeCreationHooksReceiveOnlySuppliedUnboundProperties(): void
    {
        $received = null;

        $data = ConstructorAssignedCreationData::factory()
            ->beforeCreation(function (array $properties) use (&$received): array {
                $received = $properties;

                return [...$properties, 'nickname' => 'hooked'];
            })
            ->from(['name' => 'Taylor']);

        $this->assertSame(['name' => 'Taylor'], $received);
        $this->assertSame('hooked', $data->nickname);
        $this->assertSame('label:Taylor', $data->label);
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

    #[DefineEnvironment('withIgnoredComputedInput')]
    public function testSuppliedComputedAndVirtualValuesCanBeIgnored(): void
    {
        $payload = ['id' => 1, 'computed' => 'client', 'virtual' => 'client'];

        $created = [
            DirectOutputOnlyCreationData::from($payload),
            DirectOutputOnlyCreationData::validateAndCreate($payload),
            DirectOutputOnlyCreationData::factory()
                ->alwaysValidate()
                ->beforeValidation(static fn (array $payload): array => [...$payload, 'computed' => 'hook'])
                ->from(['id' => 1]),
        ];

        foreach ($created as $data) {
            $this->assertSame(1, $data->id);
            $this->assertSame('computed', $data->computed);
            $this->assertSame('virtual', $data->virtual);
        }
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

    /**
     * Ignore supplied computed property values.
     */
    protected function withIgnoredComputedInput(Application $app): void
    {
        $app->make('config')->set('data.features.ignore_exception_when_trying_to_set_computed_property_value', true);
    }

    /**
     * Configure a global string cast.
     */
    protected function withStringToUpperCast(Application $app): void
    {
        $app->make('config')->set('data.casts', ['string' => StringToUpperCast::class]);
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

class OptionalDefaultCreationData extends Data
{
    /**
     * Create an optional default fixture.
     */
    public function __construct(
        public string|Optional $note = 'default',
    ) {
    }
}

class PrepareDataIdentityData extends Data
{
    /**
     * Create a prepare-data identity fixture.
     *
     * @param array<string, int> $meta
     */
    public function __construct(
        public string $name,
        public array $meta = [],
    ) {
    }
}

class MappedPrepareDataIdentityData extends Data
{
    /**
     * Create a mapped prepare-data identity fixture.
     */
    public function __construct(
        #[MapInputName('profile.name')]
        public string $name,
        #[MapInputName('user_code')]
        public string $code,
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

    /**
     * Create a factory without property name mapping.
     */
    public static function factory(?CreationContext $creationContext = null): CreationContextFactory
    {
        ++self::$factoryCalls;

        return parent::factory($creationContext)->withoutPropertyNameMapping();
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
        array $properties,
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

class AutoLazyNamedPayloadData extends Data
{
    public function __construct(
        #[RecordingAutoLazy]
        public Lazy|string $title,
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

class ContextualConversionCreationData extends Data
{
    /**
     * Create a fixture whose values come from route parameters.
     *
     * @param array<int, SimpleData> $items
     */
    public function __construct(
        #[RouteParameter('id')]
        public int $id,
        #[RouteParameter('status')]
        public DummyBackedEnum $status,
        #[RouteParameter('at')]
        public DateTimeImmutable $at,
        #[RouteParameter('child')]
        public SimpleData $child,
        #[RouteParameter('choice')]
        public SimpleData|string $choice,
        #[RouteParameter('items')]
        public array $items,
        #[RouteParameter('missing')]
        public ?SimpleData $missing,
    ) {
    }
}

interface ContextualCreationContract
{
}

class ContextualCreationDefault implements ContextualCreationContract
{
}

class ContextualCreationForData implements ContextualCreationContract
{
}

#[Attribute(Attribute::TARGET_PARAMETER)]
class ContextualCreationDependency implements ContextualAttribute
{
    /**
     * Resolve the class the container gives for the contract.
     */
    public static function resolve(self $attribute, Container $container): string
    {
        return $container->make(ContextualCreationContract::class)::class;
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
class ContextualCreationMarker
{
}

#[ContextualCreationMarker]
class ContextualResolutionCreationData extends Data
{
    public string $label;

    /**
     * Create a fixture with promoted and constructor-only contextual values.
     */
    public function __construct(
        #[ContextualCreationDependency]
        public string $dependency,
        #[Config('app.name')]
        string $appName,
    ) {
        $this->label = $appName;
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
            'invalid' => ChildCreationData::class,
            default => null,
        };
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

class MappedUnionChildCreationData extends Data
{
    /**
     * Create a child whose input name is mapped.
     */
    public function __construct(
        #[MapInputName('external_name')]
        public string $name,
    ) {
    }
}

class DataObjectUnionCreationData extends Data
{
    /**
     * Create a fixture whose unions keep accepted arrays and build a child from other values.
     */
    public function __construct(
        public array|MappedUnionChildCreationData $child,
        public Collection|array|MappedUnionChildCreationData|null $containers = null,
    ) {
    }

    /**
     * Require the child name, which a built child reads from its mapped input name.
     */
    public static function rules(): array
    {
        return ['child.name' => ['required']];
    }
}

class DataObjectUnionListData extends Data
{
    /**
     * Create a fixture whose items each hold a data object union.
     *
     * @param array<int, DataObjectUnionCreationData> $items
     */
    public function __construct(
        #[DataCollectionOf(DataObjectUnionCreationData::class)]
        public array $items,
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
        array $properties,
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

class TypedContainerUnionCreationData extends Data
{
    /**
     * Create a fixture whose container unions declare their item types.
     *
     * @param array<string, int>|Collection<string, int> $both
     * @param int[] $shorthand
     * @param array<int, int> $arrayOnly
     */
    public function __construct(
        public Collection|array $both,
        public Collection|array $shorthand,
        public Collection|array $arrayOnly,
    ) {
    }
}

class OverlappingContainerUnionCreationData extends Data
{
    /**
     * Create a fixture whose container types both accept a collection.
     *
     * @param Collection<int, int>|Enumerable<int, string> $values
     */
    public function __construct(
        public Collection|Enumerable $values,
    ) {
    }
}

class MixedContainerUnionCreationData extends Data
{
    /**
     * Create a fixture whose union holds a data collection and a plain array.
     *
     * @param array<array-key, int>|Collection<int, ChildCreationData> $children
     */
    public function __construct(
        public Collection|array $children,
    ) {
    }
}

class MixedContainerUnionListData extends Data
{
    /**
     * Create a fixture whose items each hold a container union.
     *
     * @param array<int, MixedContainerUnionCreationData> $items
     */
    public function __construct(
        #[DataCollectionOf(MixedContainerUnionCreationData::class)]
        public array $items,
    ) {
    }
}

class AutoLazyContainerUnionCreationData extends Data
{
    /**
     * Create a fixture whose automatic lazy container union is resolved later.
     *
     * @param array<int, int>|Collection<int, ChildCreationData>|Lazy $children
     */
    public function __construct(
        #[AutoLazy]
        public Lazy|Collection|array $children,
    ) {
    }
}

class ConfiguredItemCastUnionCreationData extends Data
{
    /**
     * Create a fixture whose container union holds strings.
     *
     * @param array<int, string>|Collection<int, string> $names
     */
    public function __construct(
        public Collection|array $names,
    ) {
    }
}

class AmbiguousDataCollectableCreationCast implements Cast
{
    public function cast(
        DataProperty $property,
        mixed $value,
        array $properties,
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
        array $properties,
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
        array $properties,
        CreationContext $context,
    ): string {
        return 'cast:' . $value;
    }
}

class RecordingInputsCast implements Cast
{
    /** @var null|array<string, mixed> */
    public static ?array $properties = null;

    /**
     * Record the declared property values and return the value.
     */
    public function cast(
        DataProperty $property,
        mixed $value,
        array $properties,
        CreationContext $context,
    ): string {
        static::$properties = $properties;

        return $value;
    }
}

class RecordingItemInputsCast implements Cast, IterableItemCast
{
    /** @var list<array<string, mixed>> */
    public static array $received = [];

    /**
     * Leave the whole property to the item casts.
     */
    public function cast(
        DataProperty $property,
        mixed $value,
        array $properties,
        CreationContext $context,
    ): Uncastable {
        return Uncastable::create();
    }

    /**
     * Record the declared property values and uppercase the item.
     */
    public function castIterableItem(
        DataProperty $property,
        mixed $value,
        array $properties,
        CreationContext $context,
    ): string {
        static::$received[] = $properties;

        return strtoupper($value);
    }
}

class CastInputsDefault
{
}

class CastInputsData extends Data
{
    #[Computed]
    public string $summary;

    /**
     * Create a cast-inputs fixture.
     */
    public function __construct(
        public int $first,
        #[WithCast(RecordingInputsCast::class)]
        public string $recorded,
        #[MapInputName('later_value')]
        public string $later,
        public int $count,
        public string|Optional $optional,
        public ?string $nullable,
        #[Config('app.name')]
        public string $appName,
        public CastInputsDefault $default = new CastInputsDefault,
    ) {
        $this->summary = $recorded;
    }
}

class CastInputsLeadingDefaultData extends Data
{
    /**
     * Create a cast-inputs fixture whose default precedes the cast property.
     */
    public function __construct(
        public CastInputsDefault $default = new CastInputsDefault,
        #[WithCast(RecordingInputsCast::class)]
        public string $recorded = 'fallback',
    ) {
    }
}

class CastInputsIterableData extends Data
{
    /**
     * Create an iterable cast-inputs fixture.
     *
     * @param list<string> $tags
     */
    public function __construct(
        public string $name,
        #[WithCast(RecordingItemInputsCast::class)]
        public array $tags,
    ) {
    }
}

class CastInputsLazyData extends Data
{
    /**
     * Create a deferred cast-inputs fixture.
     */
    public function __construct(
        public int $first,
        #[AutoLazy, WithCast(RecordingInputsCast::class)]
        public Lazy|string $recorded,
    ) {
    }
}

class FactoryCastInputsLazyData extends Data
{
    /**
     * Create a deferred fixture for a factory-supplied cast.
     */
    public function __construct(
        public int $first,
        #[AutoLazy]
        public Lazy|string $recorded,
    ) {
    }
}

class PreparedAddressData extends Data
{
    public static int $calls = 0;

    /**
     * Create a prepared address fixture.
     */
    public function __construct(
        public ?string $name = null,
        public ?string $address = null,
    ) {
    }

    /**
     * Join the flat address fields into one address.
     */
    public static function prepareForPipeline(array $properties): array
    {
        ++static::$calls;

        $properties['address'] = implode(',', Arr::only($properties, ['line_1', 'city', 'state', 'zipcode']));

        return $properties;
    }
}

class PreparedRequiredAddressData extends Data
{
    /**
     * Create a prepared required-address fixture.
     */
    public function __construct(public string $address)
    {
    }

    /**
     * Join the flat address fields into the required address.
     */
    public static function prepareForPipeline(array $properties): array
    {
        $properties['address'] = implode(', ', Arr::only($properties, ['line_1', 'city']));

        return $properties;
    }
}

class PreparedOwnerData extends Data
{
    /**
     * Create a fixture whose nested owner prepares its own input.
     */
    public function __construct(
        public string $label,
        public PreparedRequiredAddressData $owner,
    ) {
    }
}

class PreparedNameModel extends Model
{
}

class PreparedNameData extends Data
{
    /**
     * Create a prepared name fixture.
     */
    public function __construct(public string $name)
    {
    }

    /**
     * Uppercase the name before it is read.
     */
    public static function prepareForPipeline(array $properties): array
    {
        $properties['name'] = strtoupper($properties['name']);

        return $properties;
    }
}

abstract class PreparedShapeData extends Data implements PropertyMorphableData
{
    /**
     * Create a prepared shape fixture.
     */
    public function __construct(
        #[PropertyForMorph]
        public string $type,
    ) {
    }

    /**
     * Resolve the concrete shape class.
     */
    public static function morph(array $properties): ?string
    {
        return $properties['type'] === 'circle' ? PreparedCircleData::class : null;
    }
}

class PreparedCircleData extends PreparedShapeData
{
    /**
     * Create a prepared circle fixture.
     */
    public function __construct(string $type, public int $radius)
    {
        parent::__construct($type);
    }

    /**
     * Read the radius from its short input name.
     */
    public static function prepareForPipeline(array $properties): array
    {
        $properties['radius'] ??= $properties['r'] ?? null;

        return $properties;
    }
}

class CastOwnedChildData extends Data
{
    /**
     * Create a child whose input name is mapped.
     */
    public function __construct(
        #[MapInputName('external_name')]
        public string $name,
    ) {
    }

    /**
     * Fail if the child's own factory runs for a value the cast accepts.
     */
    public static function fromString(string $value): self
    {
        throw new RuntimeException('The named factory must not run when the cast accepts the value.');
    }
}

class CastOwnedChildModel extends Model
{
}

class CastOwnedNestingData extends Data
{
    /**
     * Create a parent that holds a child.
     */
    public function __construct(
        public CastOwnedChildData $child,
    ) {
    }
}

class CastOwnedWrapperData extends Data
{
    /**
     * Create a fixture whose cast owns a parent that holds a child.
     */
    public function __construct(
        #[WithCast(CastOwnedChildCast::class)]
        public CastOwnedNestingData $wrapper,
    ) {
    }
}

class CastOwnedAddressData extends Data
{
    /**
     * Create an address built from its parts.
     */
    public function __construct(public string $address)
    {
    }

    /**
     * Combine the address parts before the address is read.
     */
    public static function prepareForPipeline(array $properties): array
    {
        $properties['address'] ??= "{$properties['line_1']}, {$properties['city']}";

        return $properties;
    }
}

class CastOwnedInnerData extends Data
{
    /**
     * Create a value holding a union child and a prepared child.
     */
    public function __construct(
        public array|CastOwnedChildData $child,
        public ?CastOwnedAddressData $address = null,
    ) {
    }
}

class CastOwnedOuterData extends Data
{
    /**
     * Create a fixture whose cast owns a value with nested children.
     */
    public function __construct(
        #[WithCast(CastOwnedChildCast::class)]
        public CastOwnedInnerData $inner,
    ) {
    }
}

class CastOwnedLazyChildData extends Data
{
    /**
     * Create a child whose relation is included only once it is loaded.
     */
    public function __construct(
        public string $name,
        #[AutoWhenLoadedLazy]
        public Lazy|CastOwnedChildData|null $relation,
    ) {
    }
}

class CastOwnedLazyParentData extends Data
{
    /**
     * Create a fixture whose cast owns a child read from a model.
     */
    public function __construct(
        #[WithCast(CastOwnedChildCast::class)]
        public CastOwnedLazyChildData $child,
    ) {
    }
}

class CountingNameNormalizer implements Normalizer
{
    public static int $calls = 0;

    /**
     * Read a plain string as a name, counting each read.
     */
    public function normalize(mixed $value): array|Normalized|null
    {
        if (! is_string($value)) {
            return null;
        }

        ++self::$calls;

        return ['name' => $value];
    }
}

class NormalizedNameChildData extends Data
{
    /**
     * Create a child read from a plain name.
     */
    public function __construct(public string $name)
    {
    }

    /**
     * Get the normalizers that read this child's input.
     *
     * @return list<class-string<Normalizer>>
     */
    public static function normalizers(): array
    {
        return [CountingNameNormalizer::class];
    }
}

class CastOwnedNormalizedParentData extends Data
{
    /**
     * Create a fixture whose cast owns a child read from a plain name.
     */
    public function __construct(
        #[WithCast(CastOwnedChildCast::class)]
        public NormalizedNameChildData $child,
    ) {
    }
}

class PendingFactorySource
{
    /**
     * Create a source a named factory reads.
     */
    public function __construct(public string $name)
    {
    }
}

class PendingFactoryChildData extends Data
{
    public static int $calls = 0;

    /**
     * Create a child.
     */
    public function __construct(public string $name)
    {
    }

    /**
     * Count each run while building the child from its source.
     */
    public static function fromSource(PendingFactorySource $source): self
    {
        ++self::$calls;

        return new self("factory:{$source->name}");
    }
}

class PendingFactoryWrapperData extends Data
{
    /**
     * Create a wrapper whose children may come from a named factory.
     */
    public function __construct(
        public PendingFactoryChildData $child,
        #[Exclude]
        public ?PendingFactoryChildData $excluded = null,
    ) {
    }
}

class AcceptingWrapperCast implements Cast
{
    /**
     * Supply the wrapper without creating anything from the input.
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): PendingFactoryWrapperData
    {
        return new PendingFactoryWrapperData(new PendingFactoryChildData('cast'));
    }
}

class PendingFactoryAcceptedData extends Data
{
    /**
     * Create a fixture whose cast accepts the wrapper.
     */
    public function __construct(
        #[WithCast(AcceptingWrapperCast::class)]
        public PendingFactoryWrapperData $wrapper,
    ) {
    }
}

class PendingFactoryDeclinedData extends Data
{
    /**
     * Create a fixture whose cast declines the wrapper.
     */
    public function __construct(
        #[WithCast(CastOwnedChildCast::class)]
        public PendingFactoryWrapperData $wrapper,
    ) {
    }
}

class CastOwnedChildCast implements Cast
{
    public static mixed $received = null;

    /**
     * Record the value and build a child only from a string.
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): mixed
    {
        static::$received = $value;

        return is_string($value) ? new CastOwnedChildData("cast:{$value}") : Uncastable::create();
    }
}

class CastOwnedParentData extends Data
{
    /**
     * Create a parent whose child is owned by a cast.
     */
    public function __construct(
        #[WithCast(CastOwnedChildCast::class)]
        public CastOwnedChildData $child,
    ) {
    }
}

abstract class CastOwnedShapeData extends Data implements PropertyMorphableData
{
    /**
     * Create a property-morphable shape.
     */
    public function __construct(
        #[PropertyForMorph]
        public string $type,
    ) {
    }

    /**
     * Resolve the concrete shape class.
     */
    public static function morph(array $properties): ?string
    {
        return $properties['type'] === 'square' ? CastOwnedSquareData::class : null;
    }
}

class CastOwnedSquareData extends CastOwnedShapeData
{
    /**
     * Create a square with a concrete-only rule.
     */
    public function __construct(
        #[Min(3)]
        public string $code,
    ) {
        parent::__construct('square');
    }
}

class CastOwnedShapesData extends Data
{
    /**
     * Create a fixture with cast-owned polymorphic properties.
     *
     * @param array<int, CastOwnedShapeData> $shapes
     */
    public function __construct(
        #[WithCast(CastOwnedChildCast::class)]
        public CastOwnedShapeData $shape,
        #[WithCast(CastOwnedChildCast::class), DataCollectionOf(CastOwnedShapeData::class)]
        public array $shapes,
    ) {
    }
}

class CastOwnedUnionData extends Data
{
    /**
     * Create a fixture whose cast-owned values the cast declines.
     *
     * @param array<int, int>|CastOwnedChildData $numbers
     * @param null|LazyCollection<array-key, CastOwnedChildData> $children
     */
    public function __construct(
        #[WithCast(CastOwnedChildCast::class)]
        public array|CastOwnedChildData $child,
        #[WithCast(CastOwnedChildCast::class)]
        public array|CastOwnedChildData $numbers,
        #[WithCast(CastOwnedChildCast::class)]
        public ?LazyCollection $children = null,
    ) {
    }
}

class ConstructorInputCreationData extends Data
{
    public array $received;

    /**
     * Create a fixture whose constructor parameters have no public data properties.
     *
     * @param array<string, mixed> $options
     */
    public function __construct(string $prefix, array $options = [], protected string $secret = 'none')
    {
        $this->received = ['prefix' => $prefix, 'options' => $options, 'secret' => $secret];
    }
}

class ConstructorAssignedCreationData extends Data
{
    public ?string $nickname;

    public string|Optional $label;

    /**
     * Create a fixture whose constructor assigns an unbound property.
     */
    public function __construct(public string $name)
    {
        $this->label = "label:{$name}";
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
