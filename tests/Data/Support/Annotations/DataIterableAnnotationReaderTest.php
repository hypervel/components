<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Annotations\DataIterableAnnotationReaderTest;

use ArrayIterator;
use Countable;
use DateTimeImmutable;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Lazy;
use Hypervel\Data\Optional;
use Hypervel\Data\Support\Annotations\DataIterableAnnotation;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Factories\DataClassFactory;
use Hypervel\Data\Support\Factories\DataMethodFactory;
use Hypervel\Data\Support\Factories\DataParameterFactory;
use Hypervel\Data\Support\Factories\DataPropertyFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\ChildScope\ChildAnnotations;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ChildClassItem;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ConstructorItem;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\InlineItem;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ParentClassItem;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\TestCase;
use Iterator;
use IteratorAggregate;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Traversable;

class DataIterableAnnotationReaderTest extends TestCase
{
    // The reader parses annotations and the PHPDoc name resolver resolves their item types, where Spatie's reader
    // does both, so the upstream cases assert the resolved item and whether it is data.

    /**
     * @param null|array{string, bool} $expected
     */
    #[DataProvider('dataPropertyAnnotations')]
    public function testCanGetTheDataClassForADataCollectionByAnnotation(string $property, ?array $expected): void
    {
        $this->assertSame($expected, $this->propertyItem(CollectionDataAnnotationsData::class, $property));
    }

    /**
     * Get the data annotation properties with the item each resolves to.
     *
     * @return iterable<string, array{string, null|array{string, bool}}>
     */
    public static function dataPropertyAnnotations(): iterable
    {
        yield 'propertyA' => ['propertyA', [SimpleData::class, true]];
        yield 'propertyB' => ['propertyB', [SimpleData::class, true]];
        yield 'propertyC' => ['propertyC', [SimpleData::class, true]];
        yield 'propertyD' => ['propertyD', [SimpleData::class, true]];
        yield 'propertyE' => ['propertyE', [SimpleData::class, true]];
        yield 'propertyF' => ['propertyF', [SimpleData::class, true]];
        yield 'propertyG' => ['propertyG', [SimpleData::class, true]];
        yield 'propertyH' => ['propertyH', null]; // Attribute
        yield 'propertyI' => ['propertyI', null]; // Invalid definition
        yield 'propertyJ' => ['propertyJ', null]; // No definition
        yield 'propertyK' => ['propertyK', [SimpleData::class, true]];
        yield 'propertyL' => ['propertyL', [SimpleData::class, true]];
        yield 'propertyM' => ['propertyM', [SimpleData::class, true]];
        yield 'propertyU' => ['propertyU', [SimpleData::class, true]];
        yield 'propertyV' => ['propertyV', [SimpleDataWithUnicodeCharséÄöü::class, true]];
    }

    public function testCanGetTheDataClassForADataCollectionByClassAnnotation(): void
    {
        $annotations = (new DataIterableAnnotationReader)->getForClass(new ReflectionClass(CollectionDataAnnotationsData::class));

        $this->assertSame([
            'propertyN' => [SimpleData::class, true],
            'propertyO' => [SimpleData::class, true],
            'propertyP' => [SimpleData::class, true],
            'propertyQ' => [SimpleData::class, true],
            'propertyR' => [SimpleData::class, true],
            'propertyS' => [SimpleData::class, true],
            'propertyT' => [SimpleData::class, true],
            'propertyW' => [SimpleDataWithUnicodeCharséÄöü::class, true],
        ], $this->resolvedItems($annotations, CollectionDataAnnotationsData::class));
    }

    public function testCanGetDataClassForADataCollectionByMethodAnnotation(): void
    {
        $annotations = (new DataIterableAnnotationReader)->getForMethod(new ReflectionMethod(CollectionDataAnnotationsData::class, 'method'));

        $this->assertSame([
            'paramA' => [SimpleData::class, true],
            'paramB' => [SimpleData::class, true],
            'paramC' => [SimpleData::class, true],
            'paramD' => [SimpleData::class, true],
            'paramE' => [SimpleData::class, true],
            'paramF' => [SimpleData::class, true],
            'paramG' => [SimpleData::class, true],
            'paramH' => [SimpleData::class, true],
            'paramJ' => [SimpleData::class, true],
            'paramI' => [SimpleData::class, true],
            'paramK' => [SimpleData::class, true],
            'paramL' => [SimpleDataWithUnicodeCharséÄöü::class, true],
            'paramM' => [SimpleDataWithUnicodeCharséÄöü::class, true],
            'paramN' => [SimpleDataWithUnicodeCharséÄöü::class, true],
            'paramO' => [SimpleDataWithUnicodeCharséÄöü::class, true],
        ], $this->resolvedItems($annotations, CollectionDataAnnotationsData::class));
    }

    /**
     * @param null|array{string, bool} $expected
     */
    #[DataProvider('iterablePropertyAnnotations')]
    public function testCanGetTheIterableClassForACollectionByAnnotation(string $property, ?array $expected): void
    {
        $this->assertSame($expected, $this->propertyItem(CollectionNonDataAnnotationsData::class, $property));
    }

    /**
     * Get the non-data annotation properties with the item each resolves to.
     *
     * @return iterable<string, array{string, null|array{string, bool}}>
     */
    public static function iterablePropertyAnnotations(): iterable
    {
        yield 'propertyA' => ['propertyA', [DummyBackedEnum::class, false]];
        yield 'propertyB' => ['propertyB', [DummyBackedEnum::class, false]];
        yield 'propertyC' => ['propertyC', [DummyBackedEnum::class, false]];
        yield 'propertyD' => ['propertyD', [DummyBackedEnum::class, false]];
        yield 'propertyE' => ['propertyE', ['string', false]];
        yield 'propertyF' => ['propertyF', ['string', false]];
        yield 'propertyG' => ['propertyG', [DummyBackedEnum::class, false]];
        yield 'propertyH' => ['propertyH', null]; // Invalid
        yield 'propertyI' => ['propertyI', null]; // No definition
        yield 'propertyJ' => ['propertyJ', [DummyBackedEnum::class, false]];
        yield 'propertyK' => ['propertyK', [DummyBackedEnum::class, false]];
        yield 'propertyL' => ['propertyL', [DummyBackedEnum::class, false]];
        yield 'propertyP' => ['propertyP', [Error::class, true]];
        yield 'propertyR' => ['propertyR', [Error::class, true]];
    }

    public function testCanGetTheIterableClassForACollectionByClassAnnotation(): void
    {
        $annotations = (new DataIterableAnnotationReader)->getForClass(new ReflectionClass(CollectionNonDataAnnotationsData::class));

        $this->assertSame([
            'propertyM' => [DummyBackedEnum::class, false],
            'propertyN' => [DummyBackedEnum::class, false],
            'propertyO' => [DummyBackedEnum::class, false],
            'propertyQ' => [DummyBackedEnum::class, false],
        ], $this->resolvedItems($annotations, CollectionNonDataAnnotationsData::class));
    }

    public function testCanGetIterableClassForADataByMethodAnnotation(): void
    {
        $annotations = (new DataIterableAnnotationReader)->getForMethod(new ReflectionMethod(CollectionNonDataAnnotationsData::class, 'method'));

        $this->assertSame([
            'paramA' => [DummyBackedEnum::class, false],
            'paramB' => [DummyBackedEnum::class, false],
            'paramC' => [DummyBackedEnum::class, false],
            'paramD' => [DummyBackedEnum::class, false],
            'paramE' => [DummyBackedEnum::class, false],
            'paramF' => [DummyBackedEnum::class, false],
            'paramG' => [DummyBackedEnum::class, false],
            'paramH' => [DummyBackedEnum::class, false],
            'paramJ' => [DummyBackedEnum::class, false],
            'paramI' => [DummyBackedEnum::class, false],
            'paramK' => [DummyBackedEnum::class, false],
        ], $this->resolvedItems($annotations, CollectionNonDataAnnotationsData::class));
    }

    public function testWillAlwaysPreferTheDataVersionOfTheAnnotationInUnions(): void
    {
        $dataClass = new class extends Data {
            /** @var array<SimpleData|string> */
            public array $property;
        };
        $property = new ReflectionProperty($dataClass::class, 'property');

        // The type factory picks the data item from a union annotation, which Spatie's reader does itself.
        $type = (new DataTypeFactory(new PhpDocTypeNameResolver, new DataIterableAnnotationReader))->buildProperty(
            $property->getType(),
            $dataClass::class,
            $property,
            iterableAnnotations: (new DataIterableAnnotationReader)->getForProperty($property),
        );

        $this->assertSame(SimpleData::class, $type->getNamedTypes()[0]->dataClass);
    }

    #[DataProvider('defaultPhpTypes')]
    public function testWillRecognizeDefaultPhpTypes(string $type): void
    {
        $dataClass = new class extends Data {
            /** @var array<string> */
            public array $string;

            /** @var array<int> */
            public array $int;

            /** @var array<float> */
            public array $float;

            /** @var array<bool> */
            public array $bool;

            /** @var array<mixed> */
            public array $mixed;

            /** @var array<array> */
            public array $array;

            /** @var array<iterable> */
            public array $iterable;

            /** @var array<object> */
            public array $object;

            /** @var array<callable> */
            public array $callable;
        };

        $this->assertSame([$type, false], $this->propertyItem($dataClass::class, $type));
    }

    /**
     * Get the PHP types an iterable annotation may name.
     *
     * @return array<string, array{string}>
     */
    public static function defaultPhpTypes(): array
    {
        return [
            'string' => ['string'],
            'int' => ['int'],
            'float' => ['float'],
            'bool' => ['bool'],
            'mixed' => ['mixed'],
            'array' => ['array'],
            'iterable' => ['iterable'],
            'object' => ['object'],
            'callable' => ['callable'],
        ];
    }

    // REMOVED: 'can recognize the key of an iterable' and the int key types of the method cases; key types only fed
    // Spatie's TypeScript transformer, which is not included, so the reader keeps none.

    /**
     * Test array, generic, list, nullable, and union property annotations.
     */
    public function testPropertyAnnotationsAreParsedStructurally(): void
    {
        $reader = new DataIterableAnnotationReader;

        $array = $reader->getForProperty(new ReflectionProperty(DataIterablePropertyFixture::class, 'array'));
        $generic = $reader->getForProperty(new ReflectionProperty(DataIterablePropertyFixture::class, 'generic'));
        $list = $reader->getForProperty(new ReflectionProperty(DataIterablePropertyFixture::class, 'list'));
        $nullable = $reader->getForProperty(new ReflectionProperty(DataIterablePropertyFixture::class, 'nullable'));
        $union = $reader->getForProperty(new ReflectionProperty(DataIterablePropertyFixture::class, 'union'));

        $this->assertAnnotation($array[0], 'array', 'FooData');
        $this->assertAnnotation($generic[0], 'array', 'FooData');
        $this->assertAnnotation($list[0], 'array', 'FooData');
        $this->assertAnnotation($nullable[0], 'Collection', 'FooData');
        $this->assertAnnotation($union[0], 'array', 'FooData');
        $this->assertAnnotation($union[1], 'Collection', 'BarData');
        $this->assertSame(
            [DataIterablePropertyFixture::class],
            array_values(array_unique(array_map(
                fn (DataIterableAnnotation $annotation): string => $annotation->declaringClass,
                [...$array, ...$generic, ...$list, ...$nullable, ...$union],
            ))),
        );
        $this->assertSame([], $reader->getForProperty(
            new ReflectionProperty(DataIterablePropertyFixture::class, 'scalar'),
        ));
    }

    /**
     * Test native types screen only declarations that cannot use iterable metadata.
     */
    public function testNativeTypesScreenIterableAnnotations(): void
    {
        $factory = $this->factory(new DataIterableAnnotationReader);
        $method = new ReflectionMethod($factory, 'typeCanUseIterableAnnotation');
        $class = new ReflectionClass(DataIterableNativeTypeFixture::class);
        $expected = [
            'scalar' => false,
            'nullableScalar' => false,
            'enum' => false,
            'date' => false,
            'data' => false,
            'optional' => false,
            'lazy' => false,
            'array' => true,
            'iterable' => true,
            'mixed' => true,
            'object' => true,
            'custom' => true,
            'union' => true,
            'intersection' => true,
        ];

        foreach ($expected as $property => $canUseAnnotation) {
            $this->assertSame(
                $canUseAnnotation,
                $method->invoke($factory, $class->getProperty($property)->getType()),
                $property,
            );
        }

        $this->assertTrue($method->invoke(
            $factory,
            $class->getMethod('acceptsCallable')->getParameters()[0]->getType(),
        ));
    }

    /**
     * Test parser construction is lazy and preserves annotation precedence.
     */
    public function testParserIsBuiltOnceForEligibleMetadata(): void
    {
        $reader = new DataIterableAnnotationReader;
        $factory = $this->factory($reader);

        $factory->build(new ReflectionClass(DataIterableScalarOnlyData::class));

        $this->assertNull($this->readerProperty($reader, 'lexer'));
        $this->assertNull($this->readerProperty($reader, 'parser'));

        $class = $factory->build(new ReflectionClass(ChildAnnotations::class));
        $lexer = $this->readerProperty($reader, 'lexer');
        $parser = $this->readerProperty($reader, 'parser');

        $this->assertInstanceOf(Lexer::class, $lexer);
        $this->assertInstanceOf(PhpDocParser::class, $parser);
        $this->assertSame(ParentClassItem::class, $this->iterableItemClass($class->properties['parentOnly']));
        $this->assertSame(ChildClassItem::class, $this->iterableItemClass($class->properties['classItems']));
        $this->assertSame(InlineItem::class, $this->iterableItemClass($class->properties['inlineItems']));
        $this->assertSame(ConstructorItem::class, $this->iterableItemClass($class->properties['constructorItems']));

        $reader->getForProperty(new ReflectionProperty(DataIterablePropertyFixture::class, 'array'));

        $this->assertSame($lexer, $this->readerProperty($reader, 'lexer'));
        $this->assertSame($parser, $this->readerProperty($reader, 'parser'));
    }

    // REMOVED: 'can caches the result'; metadata is built once per worker, so the reader keeps no per-class cache.

    /**
     * @param class-string $className
     */
    #[DataProvider('collectionClasses')]
    public function testVerifiesTheCorrectCollectionAnnotationIsReturnedForAGivenClass(string $className, ?string $itemType): void
    {
        $annotation = (new DataIterableAnnotationReader)->getForCollectionClass(new ReflectionClass($className));

        if ($itemType === null) {
            $this->assertNull($annotation);

            return;
        }

        $this->assertNotNull($annotation);
        $this->assertSame($itemType, (string) $annotation->itemType);
        $this->assertSame($className, $annotation->declaringClass);
    }

    /**
     * Get collection classes with the item type each declares.
     *
     * Upstream also asserts a data flag and key type; this reader returns only the item type, and the type factory decides whether it is data.
     */
    public static function collectionClasses(): iterable
    {
        $simpleData = '\\' . SimpleData::class;
        $enum = '\\' . DummyBackedEnum::class;

        yield 'DataCollectionWithTemplate' => [DataCollectionWithTemplate::class, $simpleData];
        yield 'DataCollectionWithoutTemplate' => [DataCollectionWithoutTemplate::class, $simpleData];
        yield 'DataCollectionWithCombinationType' => [DataCollectionWithCombinationType::class, "({$enum} | {$simpleData})"];
        yield 'DataCollectionWithIntegerKey' => [DataCollectionWithIntegerKey::class, $simpleData];
        yield 'DataCollectionWithCombinationKey' => [DataCollectionWithCombinationKey::class, $simpleData];
        yield 'DataCollectionWithoutKey' => [DataCollectionWithoutKey::class, $simpleData];
        yield 'NonDataCollectionWithTemplate' => [NonDataCollectionWithTemplate::class, $enum];
        yield 'NonDataCollectionWithoutTemplate' => [NonDataCollectionWithoutTemplate::class, $enum];
        yield 'NonDataCollectionWithCombinationType' => [NonDataCollectionWithCombinationType::class, "({$enum} | {$simpleData})"];
        yield 'NonDataCollectionWithIntegerKey' => [NonDataCollectionWithIntegerKey::class, $enum];
        yield 'NonDataCollectionWithCombinationKey' => [NonDataCollectionWithCombinationKey::class, $enum];
        yield 'NonDataCollectionWithoutKey' => [NonDataCollectionWithoutKey::class, $enum];
        yield 'CollectionWhoImplementsIterator' => [CollectionWhoImplementsIterator::class, $enum];
        yield 'CollectionWhoImplementsIteratorAggregate' => [CollectionWhoImplementsIteratorAggregate::class, $enum];
        yield 'CollectionWhoImplementsNothing' => [CollectionWhoImplementsNothing::class, null];
        yield 'CollectionWithoutDocBlock' => [CollectionWithoutDocBlock::class, null];
        yield 'CollectionWithoutType' => [CollectionWithoutType::class, null];
        yield 'interface' => [Enumerable::class, null];
        yield 'unbounded template' => [LengthAwarePaginator::class, null];
    }

    /**
     * Assert one parsed iterable annotation.
     */
    protected function assertAnnotation(
        DataIterableAnnotation $annotation,
        string $container,
        string $item,
    ): void {
        $this->assertSame($container, $annotation->containerType);
        $this->assertSame($item, (string) $annotation->itemType);
    }

    /**
     * Resolve the one annotation the reader finds for a property, or null when it finds none.
     *
     * @param class-string $class
     * @return null|array{string, bool}
     */
    protected function propertyItem(string $class, string $property): ?array
    {
        $annotations = (new DataIterableAnnotationReader)->getForProperty(new ReflectionProperty($class, $property));

        if ($annotations === []) {
            return null;
        }

        $this->assertCount(1, $annotations);

        return $this->resolvedItem($annotations[0]);
    }

    /**
     * Resolve keyed class or method annotations, checking each names its declaration and declaring class.
     *
     * @param array<string, non-empty-list<DataIterableAnnotation>> $annotations
     * @param class-string $declaringClass
     * @return array<string, array{string, bool}>
     */
    protected function resolvedItems(array $annotations, string $declaringClass): array
    {
        $resolved = [];

        foreach ($annotations as $name => $declared) {
            $this->assertCount(1, $declared);
            $this->assertSame($name, $declared[0]->property);
            $this->assertSame($declaringClass, $declared[0]->declaringClass);
            $resolved[$name] = $this->resolvedItem($declared[0]);
        }

        return $resolved;
    }

    /**
     * Resolve an annotation's item type name in its declaring class, and whether it names a data class.
     *
     * @return array{string, bool}
     */
    protected function resolvedItem(DataIterableAnnotation $annotation): array
    {
        $name = (new PhpDocTypeNameResolver)->resolve(
            (string) $annotation->itemType,
            new ReflectionClass($annotation->declaringClass),
        );

        return [$name, is_a($name, BaseData::class, true)];
    }

    /**
     * Create the metadata factory with the given annotation reader.
     */
    protected function factory(DataIterableAnnotationReader $reader): DataClassFactory
    {
        $defaults = require __DIR__ . '/../../../../src/data/config/data.php';
        $config = new DataConfig(new Repository(['data' => $defaults]));
        $nameMapperResolver = new NameMapperResolver(new Container);
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver, $reader);
        $parameterFactory = new DataParameterFactory($typeFactory);

        return new DataClassFactory(
            new DataPropertyFactory($typeFactory, $config, $nameMapperResolver),
            new DataMethodFactory($parameterFactory, $typeFactory),
            $parameterFactory,
            $reader,
            $nameMapperResolver,
            $config,
        );
    }

    /**
     * Get an internal parser dependency for verification.
     */
    protected function readerProperty(DataIterableAnnotationReader $reader, string $name): ?object
    {
        return (new ReflectionProperty($reader, $name))->getValue($reader);
    }

    /**
     * Get the concrete iterable item class from property metadata.
     */
    protected function iterableItemClass(DataProperty $property): string
    {
        return $property->type->getIterableTypes()[0]->iterableItemType?->getNamedTypes()[0]->name ?? '';
    }
}

class DataIterablePropertyFixture
{
    /** @var FooData[] */
    public array $array;

    /** @var array<int, FooData> */
    public array $generic;

    /** @var list<FooData> */
    public array $list;

    /** @var null|Collection<FooData> */
    public ?object $nullable;

    /** @var array<FooData>|Collection<BarData> */
    public array|object $union;

    /** @var FooData */
    public object $scalar;
}

enum DataIterableScalarStatus: string
{
    case Ready = 'ready';
}

class DataIterableNestedData extends Data
{
    public function __construct(public int $id)
    {
    }
}

class DataIterableCustomContainer
{
}

class DataIterableNativeTypeFixture
{
    public string $scalar;

    public ?int $nullableScalar;

    public DataIterableScalarStatus $enum;

    public DateTimeImmutable $date;

    public DataIterableNestedData $data;

    public Optional|int $optional;

    public Lazy|string $lazy;

    public array $array;

    public iterable $iterable;

    public mixed $mixed;

    public object $object;

    public DataIterableCustomContainer $custom;

    public array|string $union;

    public Countable&IteratorAggregate $intersection;

    public function acceptsCallable(callable $callback): void
    {
    }
}

/**
 * @property array<FooData> $identifier
 * @property array<FooData> $name
 */
class DataIterableScalarOnlyData extends Data
{
    /** @var array<FooData> */
    public string $name;

    /**
     * @param array<FooData> $identifier
     * @param array<FooData> $name
     * @param array<FooData> $optional
     * @param array<FooData> $lazy
     * @param array<FooData> $date
     * @param array<FooData> $status
     * @param array<FooData> $child
     */
    public function __construct(
        public int $identifier,
        string $name,
        public Optional|int $optional,
        public Lazy|string $lazy,
        public DateTimeImmutable $date,
        public DataIterableScalarStatus $status,
        public DataIterableNestedData $child,
    ) {
        $this->name = $name;
    }
}

/**
 * @template TKey of array-key
 * @template TData of \Hypervel\Tests\Data\Fixtures\SimpleData
 *
 * @extends \Hypervel\Support\Collection<TKey, TData>
 */
class DataCollectionWithTemplate extends Collection
{
}

/**
 * @extends \Hypervel\Support\Collection<array-key, \Hypervel\Tests\Data\Fixtures\SimpleData>
 */
class DataCollectionWithoutTemplate extends Collection
{
}

/**
 * @extends \Hypervel\Support\Collection<array-key, \Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum|\Hypervel\Tests\Data\Fixtures\SimpleData>
 */
class DataCollectionWithCombinationType extends Collection
{
}

/**
 * @extends \Hypervel\Support\Collection<int, \Hypervel\Tests\Data\Fixtures\SimpleData>
 */
class DataCollectionWithIntegerKey extends Collection
{
}

/**
 * @extends \Hypervel\Support\Collection<int|string, \Hypervel\Tests\Data\Fixtures\SimpleData>
 */
class DataCollectionWithCombinationKey extends Collection
{
}

/**
 * @extends \Hypervel\Support\Collection<\Hypervel\Tests\Data\Fixtures\SimpleData>
 */
class DataCollectionWithoutKey extends Collection
{
}

/**
 * @template TKey of array-key
 * @template TValue of \Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum
 *
 * @extends \Hypervel\Support\Collection<TKey, TValue>
 */
class NonDataCollectionWithTemplate extends Collection
{
}

/**
 * @extends \Hypervel\Support\Collection<array-key, \Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum>
 */
class NonDataCollectionWithoutTemplate extends Collection
{
}

/**
 * @extends \Hypervel\Support\Collection<array-key, \Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum|\Hypervel\Tests\Data\Fixtures\SimpleData>
 */
class NonDataCollectionWithCombinationType extends Collection
{
}

/**
 * @extends \Hypervel\Support\Collection<int, \Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum>
 */
class NonDataCollectionWithIntegerKey extends Collection
{
}

/**
 * @extends \Hypervel\Support\Collection<int|string, \Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum>
 */
class NonDataCollectionWithCombinationKey extends Collection
{
}

/**
 * @extends \Hypervel\Support\Collection<\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum>
 */
class NonDataCollectionWithoutKey extends Collection
{
}

/**
 * @extends \Hypervel\Support\Collection<array-key, \Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum>
 */
class CollectionWhoImplementsIterator implements Iterator
{
    /**
     * Get the current item.
     */
    public function current(): mixed
    {
        return null;
    }

    /**
     * Move to the next item.
     */
    public function next(): void
    {
    }

    /**
     * Get the current key.
     */
    public function key(): mixed
    {
        return null;
    }

    /**
     * Determine whether the current position is valid.
     */
    public function valid(): bool
    {
        return false;
    }

    /**
     * Move to the first item.
     */
    public function rewind(): void
    {
    }
}

/**
 * @extends \Hypervel\Support\Collection<array-key, \Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum>
 */
class CollectionWhoImplementsIteratorAggregate implements IteratorAggregate
{
    /**
     * Get an iterator for the items.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator([]);
    }
}

/**
 * @extends \Hypervel\Support\Collection<array-key, \Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum>
 */
class CollectionWhoImplementsNothing
{
}

class CollectionWithoutDocBlock extends Collection
{
}

/**
 * @extends \Hypervel\Support\Collection
 */
class CollectionWithoutType extends Collection
{
}

class Error extends Data
{
}

class SimpleDataWithUnicodeCharséÄöü extends Data
{
    /**
     * Create a data object whose class name has non-ASCII characters.
     */
    public function __construct(
        public string $string
    ) {
    }
}

/**
 * @property DataCollection<\Hypervel\Tests\Data\Fixtures\SimpleData> $propertyN
 * @property \Hypervel\Tests\Data\Fixtures\SimpleData[] $propertyO
 * @property DataCollection<SimpleData> $propertyP
 * @property array<\Hypervel\Tests\Data\Fixtures\SimpleData> $propertyQ
 * @property \Hypervel\Tests\Data\Fixtures\SimpleData[] $propertyR
 * @property array<SimpleData> $propertyS
 * @property null|\Hypervel\Support\Collection<\Hypervel\Tests\Data\Fixtures\SimpleData> $propertyT
 * @property null|\Hypervel\Support\Collection<\Hypervel\Tests\Data\Support\Annotations\DataIterableAnnotationReaderTest\SimpleDataWithUnicodeCharséÄöü> $propertyW
 */
class CollectionDataAnnotationsData
{
    /** @var \Hypervel\Tests\Data\Fixtures\SimpleData[] */
    public array $propertyA;

    /** @var null|\Hypervel\Tests\Data\Fixtures\SimpleData[] */
    public ?array $propertyB;

    /** @var null|\Hypervel\Tests\Data\Fixtures\SimpleData[] */
    public ?array $propertyC;

    /** @var ?\Hypervel\Tests\Data\Fixtures\SimpleData[] */
    public array $propertyD;

    /** @var \Hypervel\Data\DataCollection<\Hypervel\Tests\Data\Fixtures\SimpleData> */
    public DataCollection $propertyE;

    /** @var ?\Hypervel\Data\DataCollection<\Hypervel\Tests\Data\Fixtures\SimpleData> */
    public ?DataCollection $propertyF;

    /** @var SimpleData[] */
    public array $propertyG;

    #[DataCollectionOf(SimpleData::class)]
    public DataCollection $propertyH;

    /** @var SimpleData */
    public DataCollection $propertyI;

    public DataCollection $propertyJ;

    /** @var array<\Hypervel\Tests\Data\Fixtures\SimpleData> */
    public array $propertyK;

    /** @var LengthAwarePaginator<\Hypervel\Tests\Data\Fixtures\SimpleData> */
    public LengthAwarePaginator $propertyL;

    /** @var \Hypervel\Support\Collection<\Hypervel\Tests\Data\Fixtures\SimpleData> */
    public Collection $propertyM;

    public DataCollection $propertyN;

    public DataCollection $propertyO;

    public DataCollection $propertyP;

    public array $propertyQ;

    public array $propertyR;

    public array $propertyS;

    public ?array $propertyT;

    /** @var null|\Hypervel\Support\Collection<\Hypervel\Tests\Data\Fixtures\SimpleData> */
    public ?array $propertyU;

    /** @var null|\Hypervel\Support\Collection<\Hypervel\Tests\Data\Support\Annotations\DataIterableAnnotationReaderTest\SimpleDataWithUnicodeCharséÄöü> */
    public ?array $propertyV;

    public ?array $propertyW;

    /**
     * Accept parameters annotated with data items.
     *
     * @param null|\Hypervel\Tests\Data\Fixtures\SimpleData[] $paramA
     * @param null|\Hypervel\Tests\Data\Fixtures\SimpleData[] $paramB
     * @param ?\Hypervel\Tests\Data\Fixtures\SimpleData[] $paramC
     * @param ?\Hypervel\Tests\Data\Fixtures\SimpleData[] $paramD
     * @param \Hypervel\Data\DataCollection<\Hypervel\Tests\Data\Fixtures\SimpleData> $paramE
     * @param ?\Hypervel\Data\DataCollection<\Hypervel\Tests\Data\Fixtures\SimpleData> $paramF
     * @param SimpleData[] $paramG
     * @param array<SimpleData> $paramH
     * @param array<int,SimpleData> $paramJ
     * @param array<int, SimpleData> $paramI
     * @param null|\Hypervel\Data\DataCollection<\Hypervel\Tests\Data\Fixtures\SimpleData> $paramK
     * @param null|\Hypervel\Data\DataCollection<\Hypervel\Tests\Data\Support\Annotations\DataIterableAnnotationReaderTest\SimpleDataWithUnicodeCharséÄöü> $paramL
     * @param null|Collection<\Hypervel\Tests\Data\Support\Annotations\DataIterableAnnotationReaderTest\SimpleDataWithUnicodeCharséÄöü> $paramM
     * @param null|array<\Hypervel\Tests\Data\Support\Annotations\DataIterableAnnotationReaderTest\SimpleDataWithUnicodeCharséÄöü> $paramN
     * @param null|\Hypervel\Tests\Data\Support\Annotations\DataIterableAnnotationReaderTest\SimpleDataWithUnicodeCharséÄöü[] $paramO
     */
    public function method(
        array $paramA,
        ?array $paramB,
        ?array $paramC,
        array $paramD,
        DataCollection $paramE,
        ?DataCollection $paramF,
        array $paramG,
        array $paramH,
        array $paramJ,
        array $paramI,
        ?array $paramK,
        ?DataCollection $paramL,
        ?Collection $paramM,
        ?array $paramN,
        ?array $paramO,
    ): void {
    }
}

/**
 * @property \Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum[] $propertyM
 * @property array<\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum> $propertyN
 * @property array<DummyBackedEnum> $propertyO
 * @property null|array<DummyBackedEnum> $propertyQ
 */
class CollectionNonDataAnnotationsData
{
    /** @var \Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum[] */
    public array $propertyA;

    /** @var null|\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum[] */
    public ?array $propertyB;

    /** @var null|\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum[] */
    public ?array $propertyC;

    /** @var ?\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum[] */
    public array $propertyD;

    /** @var array<string> */
    public array $propertyE;

    /** @var array<string> */
    public array $propertyF;

    /** @var DummyBackedEnum[] */
    public array $propertyG;

    /** @var DummyBackedEnum */
    public array $propertyH;

    public array $propertyI;

    /** @var array<\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum> */
    public array $propertyJ;

    /** @var LengthAwarePaginator<\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum> */
    public LengthAwarePaginator $propertyK;

    /** @var \Hypervel\Support\Collection<\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum> */
    public Collection $propertyL;

    public array $propertyM;

    public array $propertyN;

    public array $propertyO;

    /** @var \Hypervel\Support\Collection<Error> */
    public Collection $propertyP;

    public array $propertyQ;

    /** @var null|\Hypervel\Support\Collection<Error> */
    public ?Collection $propertyR;

    /**
     * Accept parameters annotated with non-data items.
     *
     * @param null|\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum[] $paramA
     * @param null|\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum[] $paramB
     * @param ?\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum[] $paramC
     * @param ?\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum[] $paramD
     * @param \Hypervel\Data\DataCollection<\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum> $paramE
     * @param ?\Hypervel\Data\DataCollection<\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum> $paramF
     * @param DummyBackedEnum[] $paramG
     * @param array<DummyBackedEnum> $paramH
     * @param array<int,DummyBackedEnum> $paramJ
     * @param array<int, DummyBackedEnum> $paramI
     * @param null|\Hypervel\Data\DataCollection<\Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum> $paramK
     */
    public function method(
        array $paramA,
        ?array $paramB,
        ?array $paramC,
        array $paramD,
        DataCollection $paramE,
        ?DataCollection $paramF,
        array $paramG,
        array $paramH,
        array $paramJ,
        array $paramI,
        ?array $paramK,
    ): void {
    }
}
