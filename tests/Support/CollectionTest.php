<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Collection;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * @internal
 * @coversNothing
 */
class CollectionTest extends TestCase
{
    public function testGroupByWithStringKey(): void
    {
        $data = new Collection([
            ['category' => 'fruit', 'name' => 'apple'],
            ['category' => 'fruit', 'name' => 'banana'],
            ['category' => 'vegetable', 'name' => 'carrot'],
        ]);

        $result = $data->groupBy('category');

        $this->assertArrayHasKey('fruit', $result->toArray());
        $this->assertArrayHasKey('vegetable', $result->toArray());
        $this->assertCount(2, $result->get('fruit'));
        $this->assertCount(1, $result->get('vegetable'));
    }

    public function testGroupByWithIntKey(): void
    {
        $data = new Collection([
            ['rating' => 5, 'name' => 'excellent'],
            ['rating' => 5, 'name' => 'great'],
            ['rating' => 3, 'name' => 'average'],
        ]);

        $result = $data->groupBy('rating');

        $this->assertArrayHasKey(5, $result->toArray());
        $this->assertArrayHasKey(3, $result->toArray());
        $this->assertCount(2, $result->get(5));
        $this->assertCount(1, $result->get(3));
    }

    public function testGroupByWithCallback(): void
    {
        $data = new Collection([
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
            ['name' => 'Charlie', 'age' => 25],
        ]);

        $result = $data->groupBy(fn ($item) => $item['age']);

        $this->assertArrayHasKey(25, $result->toArray());
        $this->assertArrayHasKey(30, $result->toArray());
        $this->assertCount(2, $result->get(25));
    }

    public function testGroupByWithBoolKey(): void
    {
        $data = new Collection([
            ['active' => true, 'name' => 'Alice'],
            ['active' => false, 'name' => 'Bob'],
            ['active' => true, 'name' => 'Charlie'],
        ]);

        $result = $data->groupBy('active');

        // Bool keys are converted to int (true => 1, false => 0)
        $this->assertArrayHasKey(1, $result->toArray());
        $this->assertArrayHasKey(0, $result->toArray());
        $this->assertCount(2, $result->get(1));
        $this->assertCount(1, $result->get(0));
    }

    public function testGroupByWithNullKey(): void
    {
        $data = new Collection([
            ['category' => 'fruit', 'name' => 'apple'],
            ['category' => null, 'name' => 'unknown'],
        ]);

        $result = $data->groupBy('category');

        $this->assertArrayHasKey('fruit', $result->toArray());
        $this->assertArrayHasKey('', $result->toArray()); // null becomes empty string
    }

    public function testGroupByWithStringableKey(): void
    {
        $data = new Collection([
            ['id' => new CollectionTestStringable('group-a'), 'value' => 1],
            ['id' => new CollectionTestStringable('group-a'), 'value' => 2],
            ['id' => new CollectionTestStringable('group-b'), 'value' => 3],
        ]);

        $result = $data->groupBy('id');

        $this->assertArrayHasKey('group-a', $result->toArray());
        $this->assertArrayHasKey('group-b', $result->toArray());
        $this->assertCount(2, $result->get('group-a'));
    }

    public function testGroupByPreservesKeys(): void
    {
        $data = new Collection([
            10 => ['category' => 'a', 'value' => 1],
            20 => ['category' => 'a', 'value' => 2],
            30 => ['category' => 'b', 'value' => 3],
        ]);

        $result = $data->groupBy('category', true);

        $this->assertEquals([10, 20], array_keys($result->get('a')->toArray()));
        $this->assertEquals([30], array_keys($result->get('b')->toArray()));
    }

    public function testGroupByWithNestedGroups(): void
    {
        $data = new Collection([
            ['type' => 'fruit', 'color' => 'red', 'name' => 'apple'],
            ['type' => 'fruit', 'color' => 'yellow', 'name' => 'banana'],
            ['type' => 'vegetable', 'color' => 'red', 'name' => 'tomato'],
        ]);

        $result = $data->groupBy(['type', 'color']);

        $this->assertArrayHasKey('fruit', $result->toArray());
        $this->assertArrayHasKey('red', $result->get('fruit')->toArray());
        $this->assertArrayHasKey('yellow', $result->get('fruit')->toArray());
    }

    public function testKeyByWithStringKey(): void
    {
        $data = new Collection([
            ['id' => 'user-1', 'name' => 'Alice'],
            ['id' => 'user-2', 'name' => 'Bob'],
        ]);

        $result = $data->keyBy('id');

        $this->assertArrayHasKey('user-1', $result->toArray());
        $this->assertArrayHasKey('user-2', $result->toArray());
        $this->assertEquals('Alice', $result->get('user-1')['name']);
    }

    public function testKeyByWithIntKey(): void
    {
        $data = new Collection([
            ['id' => 100, 'name' => 'Alice'],
            ['id' => 200, 'name' => 'Bob'],
        ]);

        $result = $data->keyBy('id');

        $this->assertArrayHasKey(100, $result->toArray());
        $this->assertArrayHasKey(200, $result->toArray());
    }

    public function testKeyByWithCallback(): void
    {
        $data = new Collection([
            ['first' => 'Alice', 'last' => 'Smith'],
            ['first' => 'Bob', 'last' => 'Jones'],
        ]);

        $result = $data->keyBy(fn ($item) => $item['first'] . '_' . $item['last']);

        $this->assertArrayHasKey('Alice_Smith', $result->toArray());
        $this->assertArrayHasKey('Bob_Jones', $result->toArray());
    }

    public function testKeyByWithStringableKey(): void
    {
        $data = new Collection([
            ['id' => new CollectionTestStringable('key-1'), 'value' => 'first'],
            ['id' => new CollectionTestStringable('key-2'), 'value' => 'second'],
        ]);

        $result = $data->keyBy('id');

        $this->assertArrayHasKey('key-1', $result->toArray());
        $this->assertArrayHasKey('key-2', $result->toArray());
    }

    public function testWhereWithStringValue(): void
    {
        $data = new Collection([
            ['id' => 1, 'status' => 'active'],
            ['id' => 2, 'status' => 'inactive'],
            ['id' => 3, 'status' => 'active'],
        ]);

        $result = $data->where('status', 'active');

        $this->assertCount(2, $result);
        $this->assertEquals([1, 3], $result->pluck('id')->values()->toArray());
    }

    public function testWhereWithIntValue(): void
    {
        $data = new Collection([
            ['id' => 1, 'count' => 10],
            ['id' => 2, 'count' => 20],
            ['id' => 3, 'count' => 10],
        ]);

        $result = $data->where('count', 10);

        $this->assertCount(2, $result);
    }

    public function testWhereWithOperator(): void
    {
        $data = new Collection([
            ['id' => 1, 'price' => 100],
            ['id' => 2, 'price' => 200],
            ['id' => 3, 'price' => 300],
        ]);

        $this->assertCount(2, $data->where('price', '>', 100));
        $this->assertCount(2, $data->where('price', '>=', 200));
        $this->assertCount(1, $data->where('price', '<', 200));
        $this->assertCount(2, $data->where('price', '!=', 200));
    }

    public function testWhereStrictWithTypes(): void
    {
        $data = new Collection([
            ['id' => 1, 'value' => '10'],
            ['id' => 2, 'value' => 10],
        ]);

        // Strict comparison - string '10' !== int 10
        $result = $data->whereStrict('value', 10);

        $this->assertCount(1, $result);
        $this->assertEquals(2, $result->first()['id']);
    }

    public function testGetArrayableItemsWithNull(): void
    {
        $data = new Collection(null);

        $this->assertEquals([], $data->toArray());
    }

    public function testGetArrayableItemsWithScalar(): void
    {
        // String
        $data = new Collection('hello');
        $this->assertEquals(['hello'], $data->toArray());

        // Int
        $data = new Collection(42);
        $this->assertEquals([42], $data->toArray());

        // Bool
        $data = new Collection(true);
        $this->assertEquals([true], $data->toArray());
    }

    public function testGetArrayableItemsWithArray(): void
    {
        $data = new Collection(['a', 'b', 'c']);

        $this->assertEquals(['a', 'b', 'c'], $data->toArray());
    }

    public function testOperatorForWhereWithNestedData(): void
    {
        $data = new Collection([
            ['user' => ['name' => 'Alice', 'age' => 25]],
            ['user' => ['name' => 'Bob', 'age' => 30]],
        ]);

        $result = $data->where('user.name', 'Alice');

        $this->assertCount(1, $result);
        $this->assertEquals(25, $result->first()['user']['age']);
    }
}

class CollectionTestStringable implements Stringable
{
    public function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
