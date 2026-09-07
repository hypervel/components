<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Enums\DataTypeKind;
use Hypervel\Data\Optional;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\DataPropertyType;
use Hypervel\Data\Support\Factories\DataAttributesCollectionFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\Types\IntersectionType;
use Hypervel\Data\Support\Types\NamedType;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Data\Support\Types\UnionType;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Support\Collection;
use Hypervel\Tests\Data\Fixtures\Types\ImportedData as GroupedImportedData;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

class DataTypeFactoryTest extends TestCase
{
    /**
     * Test primitive, nullable, mixed, and optional declarations.
     */
    public function testPrimitivePresenceTypesArePreserved(): void
    {
        $integer = $this->property('integer');
        $nullable = $this->property('nullable');
        $untyped = $this->property('untyped');
        $optional = $this->property('optional');

        $this->assertTrue($integer->acceptsValue(10));
        $this->assertFalse($integer->acceptsValue('10'));
        $this->assertTrue($nullable->isNullable);
        $this->assertTrue($nullable->acceptsValue(null));
        $this->assertTrue($untyped->isMixed);
        $this->assertTrue($untyped->isNullable);
        $this->assertTrue($untyped->acceptsValue(new stdClass));
        $this->assertTrue($optional->isOptional);
        $this->assertTrue($optional->acceptsValue(Optional::create()));
        $this->assertTrue($optional->acceptsValue('value'));
    }

    /**
     * Test union, intersection, and DNF declarations without collapsing them.
     */
    public function testCombinationTypesRetainTheirDeclarationGraph(): void
    {
        $union = $this->property('union');
        $guaranteedUnion = $this->property('guaranteedUnion');
        $intersection = $this->property('intersection');
        $dnf = $this->property('dnf');
        $both = new DataTypeFactoryBothTypes;

        $this->assertInstanceOf(UnionType::class, $union->type);
        $this->assertTrue($union->acceptsValue('value'));
        $this->assertTrue($union->acceptsValue(10));
        $this->assertFalse($union->acceptsValue(10.5));
        $this->assertTrue($guaranteedUnion->type->guaranteesType(DataTypeFactoryMarker::class));
        $this->assertFalse($union->type->guaranteesType(DataTypeFactoryMarker::class));

        $this->assertInstanceOf(IntersectionType::class, $intersection->type);
        $this->assertTrue($intersection->acceptsValue($both));
        $this->assertFalse($intersection->acceptsValue(new stdClass));
        $this->assertTrue($intersection->type->guaranteesType(DataTypeFactoryFirstType::class));

        $this->assertInstanceOf(UnionType::class, $dnf->type);
        $this->assertTrue($dnf->acceptsValue($both));
        $this->assertTrue($dnf->acceptsValue('value'));
        $this->assertFalse($dnf->acceptsValue(new stdClass));
        $this->assertFalse($dnf->type->guaranteesType(DataTypeFactoryFirstType::class));
    }

    /**
     * Test single built-in types are derived from the flattened declaration graph.
     */
    public function testSingleBuiltinTypesUseTheFlattenedDeclarationGraph(): void
    {
        $integer = $this->property('integer');
        $nullable = $this->property('nullable');
        $union = $this->property('union');
        $float = $this->property('float');
        $strings = $this->property('strings');
        $data = $this->property('data');
        $intersection = $this->property('intersection');
        $dnf = $this->property('dnf');
        $arrayKeys = $this->property('arrayKeys');

        $this->assertSame('int', $integer->type->getSingleBuiltinType());
        $this->assertSame('int', $nullable->type->getSingleBuiltinType());
        $this->assertNull($union->type->getSingleBuiltinType());
        $this->assertSame('float', $float->type->getSingleBuiltinType());

        $stringItemType = $strings->getNamedTypes()[0]->iterableItemType;

        $this->assertInstanceOf(NamedType::class, $stringItemType);
        $this->assertSame('string', $stringItemType->getSingleBuiltinType());
        $this->assertNull($data->type->getSingleBuiltinType());
        $this->assertSame('string', $dnf->type->getSingleBuiltinType());
        $this->assertSame(
            'int',
            (new UnionType([
                new UnionType([$integer->type, $data->type]),
                $intersection->type,
            ]))->getSingleBuiltinType(),
        );
        $this->assertNull(
            (new UnionType([$union->type, $float->type]))->getSingleBuiltinType(),
        );

        $arrayKeyItemType = $arrayKeys->getNamedTypes()[0]->iterableItemType;

        $this->assertInstanceOf(UnionType::class, $arrayKeyItemType);
        $this->assertNull($arrayKeyItemType->getSingleBuiltinType());
    }

    /**
     * Test PHPDoc and attribute iterable item metadata.
     */
    public function testIterableItemTypesAreCompiledFromPhpDocAndAttributes(): void
    {
        $imported = $this->property('imported');
        $attributed = $this->property('attributed');
        $unionItems = $this->property('unionItems');
        $strings = $this->property('strings');
        $ambiguousIterables = $this->property('ambiguousIterables');

        $this->assertSame(DataTypeKind::DataArray, $imported->getNamedTypes()[0]->kind);
        $this->assertSame(GroupedImportedData::class, $imported->getNamedTypes()[0]->dataClass);
        $this->assertSame(DataTypeFactoryItemData::class, $attributed->getNamedTypes()[0]->dataClass);

        $itemType = $unionItems->getNamedTypes()[0]->iterableItemType;

        $this->assertInstanceOf(UnionType::class, $itemType);
        $this->assertTrue($itemType->acceptsValue('value'));
        $this->assertTrue($itemType->acceptsValue(m::mock(DataTypeFactoryItemData::class)));
        $this->assertSame(DataTypeFactoryItemData::class, $unionItems->getNamedTypes()[0]->dataClass);
        $this->assertSame($strings->getIterableTypes()[0], $strings->getNonDataIterableType());
        $this->assertCount(2, $ambiguousIterables->getIterableTypes());
        $this->assertNull($ambiguousIterables->getNonDataIterableType());
    }

    /**
     * Test generic item metadata is limited to iterable outer types.
     */
    public function testGenericItemMetadataRequiresAnIterableOuterType(): void
    {
        $integerRange = $this->property('integerRange');
        $classString = $this->property('classString');
        $nonEmptyArray = $this->property('nonEmptyArray');

        $this->assertSame([], $integerRange->getIterableTypes());
        $this->assertNull($integerRange->getNamedTypes()[0]->iterableItemType);
        $this->assertSame([], $classString->getIterableTypes());
        $this->assertSame('string', $classString->getNamedTypes()[0]->name);
        $this->assertNull($classString->getNamedTypes()[0]->iterableItemType);
        $this->assertSame(DataTypeKind::DataArray, $nonEmptyArray->getNamedTypes()[0]->kind);
        $this->assertSame(
            DataTypeFactoryItemData::class,
            $nonEmptyArray->getNamedTypes()[0]->dataClass,
        );
    }

    /**
     * Test exact iterable annotations win before widened container matches.
     */
    public function testExactIterableAnnotationsWinRegardlessOfUnionOrder(): void
    {
        $expected = [
            EloquentCollection::class => DataTypeFactoryFirstItemData::class,
            Collection::class => DataTypeFactorySecondItemData::class,
        ];

        foreach (['annotationBaseFirst', 'annotationExactFirst'] as $property) {
            $types = [];

            foreach ($this->property($property)->getDataCollectableTypes() as $type) {
                $types[$type->name] = $type->dataClass;
            }

            $this->assertSame($expected, $types);
        }
    }

    /**
     * Test data object declarations and float widening.
     */
    public function testNamedTypesUseNativePhpAcceptanceRules(): void
    {
        $data = $this->property('data');
        $dataCollection = $this->property('dataCollection');
        $float = $this->property('float');

        $this->assertSame(DataTypeKind::DataObject, $data->getNamedTypes()[0]->kind);
        $this->assertSame($data->getNamedTypes()[0], $data->getDataObjectType());
        $this->assertSame(DataTypeFactoryItemData::class, $data->getDataObjectClass());
        $this->assertNull($data->getDataCollectableType());
        $this->assertTrue($data->acceptsValue(m::mock(DataTypeFactoryItemData::class)));
        $this->assertSame(
            $dataCollection->getNamedTypes()[0],
            $dataCollection->getDataCollectableType(),
        );
        $this->assertNull($dataCollection->getDataObjectType());
        $this->assertNull($dataCollection->getDataObjectClass());
        $this->assertTrue($float->acceptsValue(10));
        $this->assertTrue($float->acceptsValue(10.5));
    }

    /**
     * Test inherited native types use declaration and target scopes.
     */
    public function testInheritedNativeTypesUseTheirPhpScopes(): void
    {
        $factory = new DataTypeFactory(new PhpDocTypeNameResolver);
        $target = new ReflectionClass(DataTypeFactoryNativeChild::class);
        $selfProperty = new ReflectionProperty(DataTypeFactoryNativeChild::class, 'selfValue');
        $parentProperty = new ReflectionProperty(DataTypeFactoryNativeChild::class, 'parentValue');
        $constructorParameter = $target->getConstructor()?->getParameters()[0];
        $method = new ReflectionMethod(DataTypeFactoryNativeChild::class, 'fromValue');

        $this->assertNotNull($constructorParameter);
        $this->assertSame(
            DataTypeFactoryNativeParent::class,
            $factory->buildProperty(
                $selfProperty->getType(),
                $target,
                $selfProperty,
            )->getNamedTypes()[0]->name,
        );
        $this->assertSame(
            DataTypeFactoryNativeGrandparent::class,
            $factory->buildProperty(
                $parentProperty->getType(),
                $target,
                $parentProperty,
            )->getNamedTypes()[0]->name,
        );
        $this->assertSame(
            DataTypeFactoryNativeParent::class,
            $factory->build($constructorParameter->getType(), $target, $constructorParameter)
                ->getNamedTypes()[0]->name,
        );
        $this->assertSame(
            DataTypeFactoryNativeParent::class,
            $factory->build($method->getParameters()[0]->getType(), $target, $method->getParameters()[0])
                ->getNamedTypes()[0]->name,
        );
        $this->assertSame(
            DataTypeFactoryNativeChild::class,
            $factory->build($method->getReturnType(), $target, $method)->getNamedTypes()[0]->name,
        );
    }

    /**
     * Test inherited PHPDoc keywords use declaration and target scopes.
     */
    public function testInheritedPhpDocKeywordsUseTheirPhpScopes(): void
    {
        $reader = new DataIterableAnnotationReader;
        $annotations = $reader->getForClass(new ReflectionClass(DataTypeFactoryPhpDocParent::class));
        $resolved = [];

        foreach ($annotations as $property => $propertyAnnotations) {
            $resolved[$property] = $this
                ->propertyFor(
                    DataTypeFactoryPhpDocChild::class,
                    $property,
                    $propertyAnnotations,
                )
                ->getNamedTypes()[0]
                ->iterableItemType
                ?->getNamedTypes()[0]
                ->name;
        }

        $this->assertSame(
            [
                'selfValues' => DataTypeFactoryPhpDocParent::class,
                'staticValues' => DataTypeFactoryPhpDocChild::class,
                'thisValues' => DataTypeFactoryPhpDocChild::class,
                'parentValues' => DataTypeFactoryPhpDocGrandparent::class,
            ],
            $resolved,
        );
    }

    /**
     * Build metadata for one fixture property.
     */
    protected function property(string $name): DataPropertyType
    {
        $reader = new DataIterableAnnotationReader;

        return $this->propertyFor(
            DataTypeFactoryFixture::class,
            $name,
            $reader->getForProperty(new ReflectionProperty(DataTypeFactoryFixture::class, $name)),
        );
    }

    /**
     * Build metadata for one fixture property and selected annotation list.
     */
    protected function propertyFor(string $className, string $name, array $annotations): DataPropertyType
    {
        $class = new ReflectionClass($className);
        $property = new ReflectionProperty($className, $name);
        $attributes = DataAttributesCollectionFactory::buildFromReflectionProperty($property);

        return (new DataTypeFactory(new PhpDocTypeNameResolver))->buildProperty(
            $property->getType(),
            $class,
            $property,
            $attributes,
            $annotations,
        );
    }
}

interface DataTypeFactoryFirstType
{
}

interface DataTypeFactorySecondType
{
}

interface DataTypeFactoryMarker
{
}

class DataTypeFactoryFirstMarkedType implements DataTypeFactoryMarker
{
}

class DataTypeFactorySecondMarkedType implements DataTypeFactoryMarker
{
}

class DataTypeFactoryBothTypes implements DataTypeFactoryFirstType, DataTypeFactorySecondType
{
}

abstract class DataTypeFactoryItemData implements BaseData
{
}

class DataTypeFactoryFixture
{
    public int $integer;

    /** @var int<0, max> */
    public int $integerRange;

    /** @var class-string<DataTypeFactoryItemData> */
    public string $classString;

    public ?int $nullable;

    public $untyped;

    public string|Optional $optional;

    public string|int $union;

    public DataTypeFactoryFirstMarkedType|DataTypeFactorySecondMarkedType $guaranteedUnion;

    public DataTypeFactoryFirstType&DataTypeFactorySecondType $intersection;

    public (DataTypeFactoryFirstType&DataTypeFactorySecondType)|string $dnf;

    /** @var array<int, GroupedImportedData> */
    public array $imported;

    #[DataCollectionOf(DataTypeFactoryItemData::class)]
    public array $attributed;

    /** @var array<DataTypeFactoryItemData|string> */
    public array $unionItems;

    /** @var array<string> */
    public array $strings;

    /** @var array<array-key> */
    public array $arrayKeys;

    /** @var non-empty-array<int, DataTypeFactoryItemData> */
    public array $nonEmptyArray;

    /** @var array<string>|Collection<int, string> */
    public array|Collection $ambiguousIterables;

    public DataTypeFactoryItemData $data;

    #[DataCollectionOf(DataTypeFactoryItemData::class)]
    public DataCollection $dataCollection;

    /** @var Collection<int, DataTypeFactorySecondItemData>|EloquentCollection<int, DataTypeFactoryFirstItemData> */
    public EloquentCollection|Collection $annotationBaseFirst;

    /** @var Collection<int, DataTypeFactorySecondItemData>|EloquentCollection<int, DataTypeFactoryFirstItemData> */
    public EloquentCollection|Collection $annotationExactFirst;

    public float $float;
}

abstract class DataTypeFactoryFirstItemData implements BaseData
{
}

abstract class DataTypeFactorySecondItemData implements BaseData
{
}

class DataTypeFactoryNativeGrandparent
{
}

class DataTypeFactoryNativeParent extends DataTypeFactoryNativeGrandparent
{
    public self $selfValue;

    public parent $parentValue;

    /**
     * Create a new native-scope fixture.
     */
    public function __construct(public self $constructorValue)
    {
    }

    /**
     * Create a native-scope fixture from a value.
     */
    public static function fromValue(self $value): static
    {
        return new static($value);
    }
}

class DataTypeFactoryNativeChild extends DataTypeFactoryNativeParent
{
}

class DataTypeFactoryPhpDocGrandparent
{
}

/**
 * @property array<self> $selfValues
 * @property array<static> $staticValues
 * @property array<$this> $thisValues
 * @property array<parent> $parentValues
 */
class DataTypeFactoryPhpDocParent extends DataTypeFactoryPhpDocGrandparent
{
    public array $selfValues;

    public array $staticValues;

    public array $thisValues;

    public array $parentValues;
}

class DataTypeFactoryPhpDocChild extends DataTypeFactoryPhpDocParent
{
}
