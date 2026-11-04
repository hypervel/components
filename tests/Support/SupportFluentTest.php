<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use ArrayIterator;
use Hypervel\Support\Fluent;
use Hypervel\Tests\Support\Concerns\TestsInteractsWithDataContract;
use Hypervel\Tests\TestCase;
use IteratorAggregate;
use JsonException;
use ReflectionObject;

class SupportFluentTest extends TestCase
{
    use TestsInteractsWithDataContract;

    public function testAttributesAreSetByConstructor(): void
    {
        $array = ['name' => 'Taylor', 'age' => 25];
        $fluent = new Fluent($array);

        $refl = new ReflectionObject($fluent);
        $attributes = $refl->getProperty('attributes');

        $this->assertEquals($array, $attributes->getValue($fluent));
        $this->assertEquals($array, $fluent->getAttributes());
    }

    public function testAttributesAreSetByConstructorGivenstdClass(): void
    {
        $array = ['name' => 'Taylor', 'age' => 25];
        $fluent = new Fluent((object) $array);

        $refl = new ReflectionObject($fluent);
        $attributes = $refl->getProperty('attributes');

        $this->assertEquals($array, $attributes->getValue($fluent));
        $this->assertEquals($array, $fluent->getAttributes());
    }

    public function testAttributesAreSetByConstructorGivenArrayIterator(): void
    {
        $array = ['name' => 'Taylor', 'age' => 25];
        $fluent = new Fluent(new FluentArrayIteratorStub($array));

        $refl = new ReflectionObject($fluent);
        $attributes = $refl->getProperty('attributes');

        $this->assertEquals($array, $attributes->getValue($fluent));
        $this->assertEquals($array, $fluent->getAttributes());
    }

    public function testGetMethodReturnsAttribute(): void
    {
        $fluent = new Fluent(['name' => 'Taylor', 0 => 'first']);

        $this->assertSame('Taylor', $fluent->get('name'));
        $this->assertSame('first', $fluent->get(0));
        $this->assertSame('Default', $fluent->get('foo', 'Default'));
        $this->assertSame('Taylor', $fluent->name);
        $this->assertNull($fluent->foo);
    }

    public function testAllSelectsZeroAndEmptyStringKeys(): void
    {
        $fluent = new Fluent([0 => 'first', '' => 'empty', 'name' => 'Taylor']);

        $this->assertSame([0 => 'first'], $fluent->all(0));
        $this->assertSame($fluent->all([0]), $fluent->all('0'));
        $this->assertSame(['' => 'empty'], $fluent->all(''));
        $this->assertSame([0 => 'first', 'missing' => null], $fluent->all('0', 'missing'));
        $this->assertSame([], $fluent->all([]));
    }

    public function testSetMethodSetsAttribute(): void
    {
        $fluent = new Fluent;

        $fluent->set('name', 'Taylor');
        $fluent->set('developer', true);
        $fluent->set('posts', 25);
        $fluent->set('computer.color', 'silver');
        $fluent->set(0, 'first');

        $this->assertSame('Taylor', $fluent->name);
        $this->assertTrue($fluent->developer);
        $this->assertSame(25, $fluent->posts);
        $this->assertSame(['color' => 'silver'], $fluent->computer);
        $this->assertSame('first', $fluent[0]);
    }

    public function testArrayAccessToAttributes(): void
    {
        $fluent = new Fluent(['attributes' => '1']);

        $this->assertTrue(isset($fluent['attributes']));
        $this->assertEquals(1, $fluent['attributes']);

        $fluent->attributes();

        $this->assertTrue($fluent['attributes']);
    }

    public function testMagicMethodsCanBeUsedToSetAttributes(): void
    {
        $fluent = new Fluent;

        $fluent->name = 'Taylor';
        $fluent->developer();
        $fluent->age(25);

        $this->assertSame('Taylor', $fluent->name);
        $this->assertTrue($fluent->developer);
        $this->assertEquals(25, $fluent->age);
        $this->assertInstanceOf(Fluent::class, $fluent->programmer());
    }

    public function testIssetMagicMethod(): void
    {
        $array = ['name' => 'Taylor', 'age' => 25];
        $fluent = new Fluent($array);

        $this->assertTrue(isset($fluent->name));

        unset($fluent->name);

        $this->assertFalse(isset($fluent->name));
    }

    public function testToArrayReturnsAttribute(): void
    {
        $array = ['name' => 'Taylor', 'age' => 25];
        $fluent = new Fluent($array);

        $this->assertEquals($array, $fluent->toArray());
    }

    public function testToJsonEncodesTheToArrayResult(): void
    {
        $fluent = new class extends Fluent {
            /**
             * Convert the fluent instance to an array.
             */
            public function toArray(): array
            {
                return ['foo'];
            }
        };
        $results = $fluent->toJson();

        $this->assertJsonStringEqualsJsonString(json_encode(['foo']), $results);
    }

    public function testToPrettyJson(): void
    {
        $fluent = new Fluent(['foo' => 'bar', 'bar' => 'foo']);
        $results = $fluent->toPrettyJson();
        $expected = $fluent->toJson(JSON_PRETTY_PRINT);

        $this->assertJsonStringEqualsJsonString($expected, $results);
        $this->assertSame($expected, $results);
        $this->assertStringContainsString("\n", $results);
        $this->assertStringContainsString('    ', $results);
    }

    public function testToJsonThrowsForInvalidUtf8(): void
    {
        $this->expectException(JsonException::class);

        (new Fluent(['value' => "\xB1\x31"]))->toJson();
    }

    public function testToPrettyJsonPropagatesInvalidUtf8Failure(): void
    {
        $this->expectException(JsonException::class);

        (new Fluent(['value' => "\xB1\x31"]))->toPrettyJson();
    }

    public function testToJsonHonorsInvalidUtf8Substitution(): void
    {
        $fluent = new Fluent(['value' => "\xB1\x31"]);

        $this->assertSame('{"value":"\ufffd1"}', $fluent->toJson(JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function testScope(): void
    {
        $fluent = new Fluent(['user' => ['name' => 'taylor']]);
        $this->assertEquals(['taylor'], $fluent->scope('user.name')->toArray());
        $this->assertEquals(['dayle'], $fluent->scope('user.age', 'dayle')->toArray());

        $fluent = new Fluent(['products' => ['forge', 'vapour', 'spark']]);
        $this->assertEquals(['forge', 'vapour', 'spark'], $fluent->scope('products')->toArray());
        $this->assertEquals(['foo', 'bar'], $fluent->scope('missing', ['foo', 'bar'])->toArray());

        $fluent = new Fluent(['authors' => ['taylor' => ['products' => ['forge', 'vapour', 'spark']]]]);
        $this->assertEquals(['forge', 'vapour', 'spark'], $fluent->scope('authors.taylor.products')->toArray());
    }

    public function testToCollection(): void
    {
        $fluent = new Fluent(['forge', 'vapour', 'spark']);
        $this->assertEquals(['forge', 'vapour', 'spark'], $fluent->collect()->all());

        $fluent = new Fluent(['authors' => ['taylor' => ['products' => ['forge', 'vapour', 'spark']]]]);
        $this->assertEquals(['forge', 'vapour', 'spark'], $fluent->collect('authors.taylor.products')->all());
    }

    public function testStringMethod(): void
    {
        $this->assertDataStringContract();
        $this->assertDataStringStrictContract();
    }

    public function testBooleanMethod(): void
    {
        $this->assertDataBooleanContract();
        $this->assertDataBooleanStrictContract();
        $this->assertDataBooleanStrictNativeValuesContract();
    }

    public function testIntegerMethod(): void
    {
        $this->assertDataIntegerContract();
        $this->assertDataIntegerStrictContract();
    }

    public function testFloatMethod(): void
    {
        $this->assertDataFloatContract();
        $this->assertDataFloatStrictContract();
    }

    public function testArrayMethod(): void
    {
        $this->assertDataArrayContract();
        $this->assertDataArrayArrayableContract();
    }

    public function testCollectMethod(): void
    {
        $this->assertDataCollectContract();
        $this->assertDataCollectNestedContract();
    }

    public function testDateMethod(): void
    {
        $this->assertDataDateContract();
    }

    public function testEnumMethod(): void
    {
        $this->assertDataEnumContract();
        $this->assertDataEnumNestedContract();
    }

    public function testEnumsMethod(): void
    {
        $this->assertDataEnumsContract();
    }

    public function testFill(): void
    {
        $fluent = new Fluent(['name' => 'John Doe']);

        $fluent->fill([
            'email' => 'john.doe@example.com',
            'age' => 30,
        ]);

        $this->assertEquals([
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'age' => 30,
        ], $fluent->getAttributes());
    }

    public function testMacroable(): void
    {
        Fluent::macro('foo', function () {
            return $this->fill([
                'foo' => 'bar',
                'baz' => 'zal',
            ]);
        });

        $fluent = new Fluent([
            'bee' => 'ser',
        ]);

        $this->assertSame([
            'bee' => 'ser',
            'foo' => 'bar',
            'baz' => 'zal',
        ], $fluent->foo()->all());
    }

    public function testFluentIsIterable(): void
    {
        $fluent = new Fluent([
            'name' => 'Taylor',
            'role' => 'admin',
        ]);

        $result = [];

        foreach ($fluent as $key => $value) {
            $result[$key] = $value;
        }

        $this->assertSame([
            'name' => 'Taylor',
            'role' => 'admin',
        ], $result);
    }

    public function testFluentIsEmpty(): void
    {
        $fluent = new Fluent;

        $this->assertTrue($fluent->isEmpty());
        $this->assertFalse($fluent->isNotEmpty());
    }

    public function testFluentIsNotEmpty(): void
    {
        $fluent = new Fluent([
            'name' => 'Taylor',
            'role' => 'admin',
        ]);

        $this->assertTrue($fluent->isNotEmpty());
        $this->assertFalse($fluent->isEmpty());
    }

    public function testExistsMethod(): void
    {
        $this->assertDataExistsContract();
        $this->assertDataExistsNestedContract();
    }

    public function testHasMethod(): void
    {
        $this->assertDataHasContract();
        $this->assertDataHasNestedContract();
    }

    public function testHasAnyMethod(): void
    {
        $this->assertDataHasAnyContract();
        $this->assertDataHasAnyNestedContract();
    }

    public function testWhenHasMethod(): void
    {
        $this->assertDataWhenHasContract();
        $this->assertDataWhenHasNestedContract();
    }

    public function testFilledMethod(): void
    {
        $this->assertDataFilledContract();
        $this->assertDataFilledNestedContract();
    }

    public function testIsNotFilledMethod(): void
    {
        $this->assertDataIsNotFilledContract();
        $this->assertDataIsNotFilledNestedContract();
    }

    public function testAnyFilledMethod(): void
    {
        $this->assertDataAnyFilledContract();
        $this->assertDataAnyFilledNestedContract();
    }

    public function testWhenFilledMethod(): void
    {
        $this->assertDataWhenFilledContract();
        $this->assertDataWhenFilledNestedContract();
    }

    public function testWhenEnumMethod(): void
    {
        $this->assertDataWhenEnumContract();
    }

    public function testMissingMethod(): void
    {
        $this->assertDataMissingContract();
        $this->assertDataMissingNestedContract();
    }

    public function testWhenMissingMethod(): void
    {
        $this->assertDataWhenMissingContract();
        $this->assertDataWhenMissingNestedContract();
    }

    public function testStrMethod(): void
    {
        $this->assertDataStrContract();
    }

    public function testClampMethod(): void
    {
        $this->assertDataClampContract();
    }

    public function testIntervalMethod(): void
    {
        $this->assertDataIntervalContract();
    }

    public function testOnlyMethod(): void
    {
        $this->assertDataOnlyContract();
        $this->assertDataOnlyNestedContract();
    }

    public function testExceptMethod(): void
    {
        $this->assertDataExceptContract();
        $this->assertDataExceptNestedContract();
    }

    public function testNullValues(): void
    {
        $this->assertDataNullValuesContract();
        $this->assertDataNullValuesIgnoreDefaultsContract();
        $this->assertDataNestedNullValuesContract();
    }

    /**
     * Create the data instance under test from the given array.
     */
    protected static function arrayToDataFactory(array $data): object
    {
        return new Fluent($data);
    }
}

class FluentArrayIteratorStub implements IteratorAggregate
{
    protected array $items = [];

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }
}
