<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Annotations;

use ArrayIterator;
use Countable;
use DateTimeImmutable;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Data\Data;
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
     * Test class property and method parameter annotations.
     */
    public function testClassAndMethodAnnotationsAreKeyedByTheirDeclarationNames(): void
    {
        $reader = new DataIterableAnnotationReader;
        $class = $reader->getForClass(new ReflectionClass(DataIterableClassFixture::class));
        $method = $reader->getForMethod(new ReflectionMethod(DataIterableClassFixture::class, 'handle'));

        $this->assertSame(['items'], array_keys($class));
        $this->assertSame('items', $class['items'][0]->property);
        $this->assertSame(DataIterableClassFixture::class, $class['items'][0]->declaringClass);
        $this->assertAnnotation($class['items'][0], 'array', 'FooData');

        $this->assertSame(['values'], array_keys($method));
        $this->assertSame('values', $method['values'][0]->property);
        $this->assertSame(DataIterableClassFixture::class, $method['values'][0]->declaringClass);
        $this->assertAnnotation($method['values'][0], 'Collection', 'FooData');
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

/** @property array<string, FooData> $items */
class DataIterableClassFixture
{
    public array $items;

    /**
     * Handle the given values.
     *
     * @param Collection<int, FooData> $values
     */
    public function handle(object $values): void
    {
    }
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
