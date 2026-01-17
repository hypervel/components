<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CollectionEnumTest extends TestCase
{
    public function testCollectionFromUnitEnum(): void
    {
        $data = new Collection(TestUnitEnum::Foo);

        $this->assertEquals([TestUnitEnum::Foo], $data->toArray());
        $this->assertCount(1, $data);
    }

    public function testCollectionFromBackedEnum(): void
    {
        $data = new Collection(TestBackedEnum::Foo);

        $this->assertEquals([TestBackedEnum::Foo], $data->toArray());
        $this->assertCount(1, $data);
    }

    public function testCollectionFromStringBackedEnum(): void
    {
        $data = new Collection(TestStringBackedEnum::Foo);

        $this->assertEquals([TestStringBackedEnum::Foo], $data->toArray());
        $this->assertCount(1, $data);
    }

    public function testGroupByWithUnitEnumKey(): void
    {
        $data = new Collection([
            ['name' => TestUnitEnum::Foo, 'value' => 1],
            ['name' => TestUnitEnum::Foo, 'value' => 2],
            ['name' => TestUnitEnum::Bar, 'value' => 3],
        ]);

        $result = $data->groupBy('name');

        $this->assertArrayHasKey('Foo', $result->toArray());
        $this->assertArrayHasKey('Bar', $result->toArray());
        $this->assertCount(2, $result->get('Foo'));
        $this->assertCount(1, $result->get('Bar'));
    }

    public function testGroupByWithBackedEnumKey(): void
    {
        $data = new Collection([
            ['rating' => TestBackedEnum::Foo, 'url' => '1'],
            ['rating' => TestBackedEnum::Bar, 'url' => '2'],
        ]);

        $result = $data->groupBy('rating');

        $expected = [
            TestBackedEnum::Foo->value => [['rating' => TestBackedEnum::Foo, 'url' => '1']],
            TestBackedEnum::Bar->value => [['rating' => TestBackedEnum::Bar, 'url' => '2']],
        ];

        $this->assertEquals($expected, $result->toArray());
    }

    public function testGroupByWithStringBackedEnumKey(): void
    {
        $data = new Collection([
            ['category' => TestStringBackedEnum::Foo, 'value' => 1],
            ['category' => TestStringBackedEnum::Foo, 'value' => 2],
            ['category' => TestStringBackedEnum::Bar, 'value' => 3],
        ]);

        $result = $data->groupBy('category');

        $this->assertArrayHasKey(TestStringBackedEnum::Foo->value, $result->toArray());
        $this->assertArrayHasKey(TestStringBackedEnum::Bar->value, $result->toArray());
    }

    public function testGroupByWithCallableReturningEnum(): void
    {
        $data = new Collection([
            ['value' => 1],
            ['value' => 2],
            ['value' => 3],
        ]);

        $result = $data->groupBy(fn ($item) => $item['value'] <= 2 ? TestUnitEnum::Foo : TestUnitEnum::Bar);

        $this->assertArrayHasKey('Foo', $result->toArray());
        $this->assertArrayHasKey('Bar', $result->toArray());
        $this->assertCount(2, $result->get('Foo'));
        $this->assertCount(1, $result->get('Bar'));
    }

    public function testKeyByWithUnitEnumKey(): void
    {
        $data = new Collection([
            ['name' => TestUnitEnum::Foo, 'value' => 1],
            ['name' => TestUnitEnum::Bar, 'value' => 2],
        ]);

        $result = $data->keyBy('name');

        $this->assertArrayHasKey('Foo', $result->toArray());
        $this->assertArrayHasKey('Bar', $result->toArray());
        $this->assertEquals(1, $result->get('Foo')['value']);
        $this->assertEquals(2, $result->get('Bar')['value']);
    }

    public function testKeyByWithBackedEnumKey(): void
    {
        $data = new Collection([
            ['rating' => TestBackedEnum::Foo, 'value' => 'first'],
            ['rating' => TestBackedEnum::Bar, 'value' => 'second'],
        ]);

        $result = $data->keyBy('rating');

        $this->assertArrayHasKey(TestBackedEnum::Foo->value, $result->toArray());
        $this->assertArrayHasKey(TestBackedEnum::Bar->value, $result->toArray());
    }

    public function testKeyByWithCallableReturningEnum(): void
    {
        $data = new Collection([
            ['id' => 1, 'value' => 'first'],
            ['id' => 2, 'value' => 'second'],
        ]);

        $result = $data->keyBy(fn ($item) => $item['id'] === 1 ? TestUnitEnum::Foo : TestUnitEnum::Bar);

        $this->assertArrayHasKey('Foo', $result->toArray());
        $this->assertArrayHasKey('Bar', $result->toArray());
    }

    public function testWhereWithEnumValue(): void
    {
        $data = new Collection([
            ['id' => 1, 'status' => TestBackedEnum::Foo],
            ['id' => 2, 'status' => TestBackedEnum::Bar],
            ['id' => 3, 'status' => TestBackedEnum::Foo],
        ]);

        $result = $data->where('status', TestBackedEnum::Foo);

        $this->assertCount(2, $result);
        $this->assertEquals([1, 3], $result->pluck('id')->values()->toArray());
    }

    public function testWhereWithUnitEnumValue(): void
    {
        $data = new Collection([
            ['id' => 1, 'type' => TestUnitEnum::Foo],
            ['id' => 2, 'type' => TestUnitEnum::Bar],
            ['id' => 3, 'type' => TestUnitEnum::Foo],
        ]);

        $result = $data->where('type', TestUnitEnum::Foo);

        $this->assertCount(2, $result);
        $this->assertEquals([1, 3], $result->pluck('id')->values()->toArray());
    }

    public function testFirstWhereWithEnum(): void
    {
        $data = new Collection([
            ['id' => 1, 'name' => TestUnitEnum::Foo],
            ['id' => 2, 'name' => TestUnitEnum::Bar],
            ['id' => 3, 'name' => TestUnitEnum::Baz],
        ]);

        $this->assertSame(2, $data->firstWhere('name', TestUnitEnum::Bar)['id']);
        $this->assertSame(3, $data->firstWhere('name', TestUnitEnum::Baz)['id']);
    }

    public function testMapIntoWithIntBackedEnum(): void
    {
        $data = new Collection([1, 2]);

        $result = $data->mapInto(TestBackedEnum::class);

        $this->assertSame(TestBackedEnum::Foo, $result->get(0));
        $this->assertSame(TestBackedEnum::Bar, $result->get(1));
    }

    public function testMapIntoWithStringBackedEnum(): void
    {
        $data = new Collection(['foo', 'bar']);

        $result = $data->mapInto(TestStringBackedEnum::class);

        $this->assertSame(TestStringBackedEnum::Foo, $result->get(0));
        $this->assertSame(TestStringBackedEnum::Bar, $result->get(1));
    }

    public function testCollectHelperWithUnitEnum(): void
    {
        $data = collect(TestUnitEnum::Foo);

        $this->assertEquals([TestUnitEnum::Foo], $data->toArray());
        $this->assertCount(1, $data);
    }

    public function testCollectHelperWithBackedEnum(): void
    {
        $data = collect(TestBackedEnum::Bar);

        $this->assertEquals([TestBackedEnum::Bar], $data->toArray());
        $this->assertCount(1, $data);
    }

    public function testWhereStrictWithEnums(): void
    {
        $data = new Collection([
            ['id' => 1, 'status' => TestBackedEnum::Foo],
            ['id' => 2, 'status' => TestBackedEnum::Bar],
        ]);

        $result = $data->whereStrict('status', TestBackedEnum::Foo);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result->first()['id']);
    }

    public function testEnumValuesArePreservedInCollection(): void
    {
        $data = new Collection([TestUnitEnum::Foo, TestBackedEnum::Bar, TestStringBackedEnum::Baz]);

        $this->assertSame(TestUnitEnum::Foo, $data->get(0));
        $this->assertSame(TestBackedEnum::Bar, $data->get(1));
        $this->assertSame(TestStringBackedEnum::Baz, $data->get(2));
    }

    public function testContainsWithEnum(): void
    {
        $data = new Collection([TestUnitEnum::Foo, TestUnitEnum::Bar]);

        $this->assertTrue($data->contains(TestUnitEnum::Foo));
        $this->assertTrue($data->contains(TestUnitEnum::Bar));
        $this->assertFalse($data->contains(TestUnitEnum::Baz));
    }

    public function testGroupByMixedEnumTypes(): void
    {
        $payload = [
            ['name' => TestUnitEnum::Foo, 'url' => '1'],
            ['name' => TestBackedEnum::Foo, 'url' => '1'],
            ['name' => TestStringBackedEnum::Foo, 'url' => '2'],
        ];

        $data = new Collection($payload);
        $result = $data->groupBy('name');

        // UnitEnum uses name ('Foo'), IntBackedEnum uses value (1), StringBackedEnum uses value ('foo')
        $this->assertEquals([
            'Foo' => [$payload[0]],
            1 => [$payload[1]],
            'foo' => [$payload[2]],
        ], $result->toArray());
    }
}

enum TestUnitEnum
{
    case Foo;
    case Bar;
    case Baz;
}

enum TestBackedEnum: int
{
    case Foo = 1;
    case Bar = 2;
    case Baz = 3;
}

enum TestStringBackedEnum: string
{
    case Foo = 'foo';
    case Bar = 'bar';
    case Baz = 'baz';
}
