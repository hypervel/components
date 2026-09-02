<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use Hypervel\Data\Support\Annotations\DataIterableAnnotation;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Tests\TestCase;
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

        $this->assertAnnotation($array[0], 'array', 'FooData', 'array-key');
        $this->assertAnnotation($generic[0], 'array', 'FooData', 'int');
        $this->assertAnnotation($list[0], 'array', 'FooData', 'int');
        $this->assertAnnotation($nullable[0], 'Collection', 'FooData', 'array-key');
        $this->assertAnnotation($union[0], 'array', 'FooData', 'array-key');
        $this->assertAnnotation($union[1], 'Collection', 'BarData', 'array-key');
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
        $this->assertAnnotation($class['items'][0], 'array', 'FooData', 'string');

        $this->assertSame(['values'], array_keys($method));
        $this->assertSame('values', $method['values'][0]->property);
        $this->assertSame(DataIterableClassFixture::class, $method['values'][0]->declaringClass);
        $this->assertAnnotation($method['values'][0], 'Collection', 'FooData', 'int');
    }

    /**
     * Assert one parsed iterable annotation.
     */
    protected function assertAnnotation(
        DataIterableAnnotation $annotation,
        string $container,
        string $item,
        string $key,
    ): void {
        $this->assertSame($container, $annotation->containerType);
        $this->assertSame($item, (string) $annotation->itemType);
        $this->assertSame($key, (string) $annotation->keyType);
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
