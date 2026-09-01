<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Creation;

use DateTimeImmutable;
use Hypervel\Container\Attributes\Config;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Data\Exceptions\CannotCreateAbstractClass;
use Hypervel\Data\Exceptions\CannotSetComputedValue;
use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Optional;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Resource;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Testbench\TestCase;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;

class DataCreatorTest extends TestCase
{
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

        $this->assertArrayHasKey('items.tenant\\.eu.profile.name', $rules);
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
        $this->expectExceptionMessage('ambiguous data-object union');

        AmbiguousCreationData::from(['child' => ['id' => 1]]);
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

    public function testRejectsSuppliedComputedValuesAndInvalidAfterCreationResults(): void
    {
        try {
            ComputedCreationData::from(['id' => 1, 'summary' => 'client']);
            $this->fail('Expected computed input to be rejected.');
        } catch (CannotSetComputedValue $exception) {
            $this->assertStringContainsString('ComputedCreationData::$summary', $exception->getMessage());
        }

        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessage('instead of an instance of');

        BasicCreationData::factory()
            ->afterCreation(fn (): ChildCreationData => new ChildCreationData(1))
            ->from(['name' => 'Taylor']);
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

enum CreationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
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
