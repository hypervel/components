<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\LazyCollection;
use PHPUnit\Framework\TestCase;

enum LazyCollectionTestUnitEnum
{
    case Foo;
    case Bar;
}

enum LazyCollectionTestIntEnum: int
{
    case Foo = 1;
    case Bar = 2;
}

enum LazyCollectionTestStringEnum: string
{
    case Foo = 'foo';
    case Bar = 'bar';
}

/**
 * @internal
 * @coversNothing
 */
class LazyCollectionTest extends TestCase
{
    public function testCountByWithStringField(): void
    {
        $data = new LazyCollection([
            ['category' => 'electronics'],
            ['category' => 'electronics'],
            ['category' => 'clothing'],
        ]);

        $result = $data->countBy('category');

        $this->assertEquals(['electronics' => 2, 'clothing' => 1], $result->all());
    }

    public function testCountByWithCallback(): void
    {
        $data = new LazyCollection([1, 2, 3, 4, 5]);

        $result = $data->countBy(fn ($value) => $value % 2 === 0 ? 'even' : 'odd');

        $this->assertEquals(['odd' => 3, 'even' => 2], $result->all());
    }

    public function testCountByWithNullCallback(): void
    {
        $data = new LazyCollection(['a', 'b', 'a', 'c', 'a']);

        $result = $data->countBy();

        $this->assertEquals(['a' => 3, 'b' => 1, 'c' => 1], $result->all());
    }

    public function testCountByWithIntegerKeys(): void
    {
        $data = new LazyCollection([
            ['rating' => 5],
            ['rating' => 3],
            ['rating' => 5],
            ['rating' => 5],
        ]);

        $result = $data->countBy('rating');

        $this->assertEquals([5 => 3, 3 => 1], $result->all());
    }

    public function testCountByIsLazy(): void
    {
        $called = 0;

        $data = new LazyCollection(function () use (&$called) {
            for ($i = 0; $i < 5; ++$i) {
                ++$called;
                yield ['type' => $i % 2 === 0 ? 'even' : 'odd'];
            }
        });

        $result = $data->countBy('type');

        // Generator not yet consumed
        $this->assertEquals(0, $called);

        // Now consume
        $result->all();
        $this->assertEquals(5, $called);
    }

    public function testCountByWithUnitEnum(): void
    {
        $data = new LazyCollection([
            ['type' => LazyCollectionTestUnitEnum::Foo],
            ['type' => LazyCollectionTestUnitEnum::Foo],
            ['type' => LazyCollectionTestUnitEnum::Bar],
        ]);

        $result = $data->countBy('type');

        $this->assertEquals(['Foo' => 2, 'Bar' => 1], $result->all());
    }

    public function testCountByWithStringBackedEnum(): void
    {
        $data = new LazyCollection([
            ['category' => LazyCollectionTestStringEnum::Foo],
            ['category' => LazyCollectionTestStringEnum::Bar],
            ['category' => LazyCollectionTestStringEnum::Foo],
        ]);

        $result = $data->countBy('category');

        $this->assertEquals(['foo' => 2, 'bar' => 1], $result->all());
    }

    public function testCountByWithIntBackedEnum(): void
    {
        $data = new LazyCollection([
            ['rating' => LazyCollectionTestIntEnum::Foo],
            ['rating' => LazyCollectionTestIntEnum::Bar],
            ['rating' => LazyCollectionTestIntEnum::Foo],
        ]);

        $result = $data->countBy('rating');

        // Int-backed enum values should be used as keys
        $this->assertEquals([1 => 2, 2 => 1], $result->all());
    }

    public function testCountByWithCallableReturningEnum(): void
    {
        $data = new LazyCollection([
            ['value' => 1],
            ['value' => 2],
            ['value' => 3],
        ]);

        $result = $data->countBy(fn ($item) => $item['value'] <= 2 ? LazyCollectionTestUnitEnum::Foo : LazyCollectionTestUnitEnum::Bar);

        $this->assertEquals(['Foo' => 2, 'Bar' => 1], $result->all());
    }
}
