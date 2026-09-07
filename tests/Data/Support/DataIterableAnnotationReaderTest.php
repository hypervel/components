<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

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
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\ChildScope\ChildAnnotations;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ChildClassItem;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ConstructorItem;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\InlineItem;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ParentClassItem;
use Hypervel\Tests\TestCase;
use IteratorAggregate;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

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
        $defaults = require __DIR__ . '/../../../src/data/config/data.php';
        $config = new DataConfig(new Repository(['data' => $defaults]));
        $nameMapperResolver = new NameMapperResolver(new Container);
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver);
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
