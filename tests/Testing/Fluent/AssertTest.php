<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Fluent;

use Hypervel\Support\Collection;
use Hypervel\Testing\Fluent\AssertableJson;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Testing\Fixtures\ArrayableStubObject;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use TypeError;

class AssertTest extends TestCase
{
    public function testAssertHas(): void
    {
        $assert = AssertableJson::fromArray([
            'prop' => 'value',
        ]);

        $assert->has('prop');
    }

    public function testAssertHasFailsWhenPropMissing(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'value',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [prop] does not exist.');

        $assert->has('prop');
    }

    public function testAssertHasNestedProp(): void
    {
        $assert = AssertableJson::fromArray([
            'example' => [
                'nested' => 'nested-value',
            ],
        ]);

        $assert->has('example.nested');
    }

    public function testAssertHasFailsWhenNestedPropMissing(): void
    {
        $assert = AssertableJson::fromArray([
            'example' => [
                'nested' => 'nested-value',
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [example.another] does not exist.');

        $assert->has('example.another');
    }

    public function testAssertHasCountItemsInProp(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => 'example',
                'prop' => 'value',
            ],
        ]);

        $assert->has('bar', 2);
    }

    public function testAssertHasCountFailsWhenAmountOfItemsDoesNotMatch(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => 'example',
                'prop' => 'value',
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] does not have the expected size.');

        $assert->has('bar', 1);
    }

    public function testAssertHasCountFailsWhenPropMissing(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => 'example',
                'prop' => 'value',
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [baz] does not exist.');

        $assert->has('baz', 1);
    }

    public function testAssertHasFailsWhenSecondArgumentUnsupportedType(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'baz',
        ]);

        $this->expectException(TypeError::class);

        $assert->has('bar', 'invalid');
    }

    public function testAssertHasOnlyCounts(): void
    {
        $assert = AssertableJson::fromArray([
            'foo',
            'bar',
            'baz',
        ]);

        $assert->has(3);
    }

    public function testAssertHasOnlyCountFails(): void
    {
        $assert = AssertableJson::fromArray([
            'foo',
            'bar',
            'baz',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Root level does not have the expected size.');

        $assert->has(2);
    }

    public function testAssertHasOnlyCountFailsScoped(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => 'example',
                'prop' => 'value',
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] does not have the expected size.');

        $assert->has('bar', function ($bar) {
            $bar->has(3);
        });
    }

    public function testAssertHasWithWhereNotDoesNotFail(): void
    {
        $assert = AssertableJson::fromArray([
            'data' => [
                [
                    'id' => 1,
                    'name' => 'Taylor',
                ],
                [
                    'id' => 2,
                    'name' => 'Nuno',
                ],
            ],
        ]);

        $assert->has('data', function ($bar) {
            $bar->has(2)
                ->each(fn ($json) => $json->whereNot('id', 3)->etc());
        });
    }

    public function testAssertHasWithWhereNotFails(): void
    {
        $assert = AssertableJson::fromArray([
            'data' => [
                [
                    'id' => 1,
                    'name' => 'Taylor',
                ],
                [
                    'id' => 2,
                    'name' => 'Mateus',
                ],
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [data.1.id] contains a value that should be missing: [id, 2]');

        $assert->has('data', function ($bar) {
            $bar->has(2)
                ->each(fn ($json) => $json->whereNot('id', 2)->etc());
        });
    }

    public function testAssertHasWithWhereNotDoesNotFailClosure(): void
    {
        $assert = AssertableJson::fromArray([
            'data' => [
                [
                    'id' => 1,
                    'name' => 'Taylor',
                ],
                [
                    'id' => 2,
                    'name' => 'Mateus',
                ],
            ],
        ]);

        $assert->has('data', function ($bar) {
            $bar->has(2)
                ->each(fn ($json) => $json->whereNot('id', fn ($value) => $value === 3)->etc());
        });
    }

    public function testAssertHasWithWhereNotFailsClosure(): void
    {
        $assert = AssertableJson::fromArray([
            'data' => [
                [
                    'id' => 1,
                    'name' => 'Taylor',
                ],
                [
                    'id' => 2,
                    'name' => 'Mateus',
                ],
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [data.1.id] was marked as invalid using a closure.');

        $assert->has('data', function ($bar) {
            $bar->has(2)
                ->each(fn ($json) => $json->whereNot('id', fn ($value) => $value === 2)->etc());
        });
    }

    public function testAssertCount(): void
    {
        $assert = AssertableJson::fromArray([
            'foo',
            'bar',
            'baz',
        ]);

        $assert->count(3);
    }

    public function testAssertCountFails(): void
    {
        $assert = AssertableJson::fromArray([
            'foo',
            'bar',
            'baz',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Root level does not have the expected size.');

        $assert->count(2);
    }

    public function testAssertCountFailsScoped(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => 'example',
                'prop' => 'value',
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] does not have the expected size.');

        $assert->has('bar', function ($bar) {
            $bar->count(3);
        });
    }

    public function testAssertBetween(): void
    {
        $assert = AssertableJson::fromArray([
            'foo',
            'bar',
            'baz',
        ]);

        $assert->countBetween(1, 3);
    }

    public function testAssertBetweenFails(): void
    {
        $assert = AssertableJson::fromArray([
            'foo',
            'bar',
            'baz',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Root level size is not less than or equal to [2].');

        $assert->countBetween(1, 2);
    }

    public function testAssertBetweenLowestValueFails(): void
    {
        $assert = AssertableJson::fromArray([
            'foo',
            'bar',
            'baz',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Root level size is not greater than or equal to [4].');

        $assert->countBetween(4, 3);
    }

    public function testAssertBetweenFailsScoped(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => 'example',
                'prop' => 'value',
                'foo' => 'value',
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] size is not less than or equal to [2].');

        $assert->has('bar', function (AssertableJson $bar) {
            $bar->countBetween(1, 2);
        });
    }

    public function testAssertMissing(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [
                'bar' => true,
            ],
        ]);

        $assert->missing('foo.baz');
    }

    public function testAssertMissingFailsWhenPropExists(): void
    {
        $assert = AssertableJson::fromArray([
            'prop' => 'value',
            'foo' => [
                'bar' => true,
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo.bar] was found while it was expected to be missing.');

        $assert->missing('foo.bar');
    }

    public function testAssertMissingAll(): void
    {
        $assert = AssertableJson::fromArray([
            'baz' => 'foo',
        ]);

        $assert->missingAll([
            'foo',
            'bar',
        ]);
    }

    public function testAssertMissingAllFailsWhenAtLeastOnePropExists(): void
    {
        $assert = AssertableJson::fromArray([
            'baz' => 'foo',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [baz] was found while it was expected to be missing.');

        $assert->missingAll([
            'bar',
            'baz',
        ]);
    }

    public function testAssertMissingAllAcceptsMultipleArgumentsInsteadOfArray(): void
    {
        $assert = AssertableJson::fromArray([
            'baz' => 'foo',
        ]);

        $assert->missingAll('foo', 'bar');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [baz] was found while it was expected to be missing.');

        $assert->missingAll('bar', 'baz');
    }

    public function testAssertWhereMatchesValue(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'value',
        ]);

        $assert->where('bar', 'value');
    }

    public function testAssertWhereFailsWhenDoesNotMatchValue(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'value',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] does not match the expected value.');

        $assert->where('bar', 'invalid');
    }

    public function testAssertWhereFailsWhenMissing(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'value',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [baz] does not exist.');

        $assert->where('baz', 'invalid');
    }

    public function testAssertWhereFailsWhenMatchingLoosely(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 1,
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] does not match the expected value.');

        $assert->where('bar', true);
    }

    public function testAssertWhereUsingClosure(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'baz',
        ]);

        $assert->where('bar', function ($value) {
            return $value === 'baz';
        });
    }

    public function testAssertWhereFailsWhenDoesNotMatchValueUsingClosure(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'baz',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] was marked as invalid using a closure.');

        $assert->where('bar', function ($value) {
            return $value === 'invalid';
        });
    }

    public function testAssertWhereClosureArrayValuesAreAutomaticallyCastedToCollections(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => 'foo',
                'example' => 'value',
            ],
        ]);

        $assert->where('bar', function ($value) {
            $this->assertInstanceOf(Collection::class, $value);

            return $value->count() === 2;
        });
    }

    public function testAssertWhereMatchesValueUsingArrayable(): void
    {
        $stub = ArrayableStubObject::make(['foo' => 'bar']);

        $assert = AssertableJson::fromArray([
            'bar' => $stub->toArray(),
        ]);

        $assert->where('bar', $stub);
    }

    public function testAssertWhereMatchesValueUsingArrayableWhenSortedDifferently(): void
    {
        $assert = AssertableJson::fromArray([
            'data' => [
                'status' => 200,
                'user' => [
                    'id' => 1,
                    'name' => 'Taylor',
                ],
            ],
        ]);

        $assert->where('data', [
            'user' => [
                'name' => 'Taylor',
                'id' => 1,
            ],
            'status' => 200,
        ]);
    }

    public function testAssertWhereFailsWhenDoesNotMatchValueUsingArrayable(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => ['id' => 1, 'name' => 'Example'],
            'baz' => [
                'id' => 1,
                'name' => 'Taylor Otwell',
                'email' => 'taylor@laravel.com',
                'email_verified_at' => '2021-01-22T10:34:42.000000Z',
                'created_at' => '2021-01-22T10:34:42.000000Z',
                'updated_at' => '2021-01-22T10:34:42.000000Z',
            ],
        ]);

        $assert
            ->where('bar', ArrayableStubObject::make(['name' => 'Example', 'id' => 1]))
            ->where('baz', [
                'name' => 'Taylor Otwell',
                'email' => 'taylor@laravel.com',
                'id' => 1,
                'email_verified_at' => '2021-01-22T10:34:42.000000Z',
                'updated_at' => '2021-01-22T10:34:42.000000Z',
                'created_at' => '2021-01-22T10:34:42.000000Z',
            ]);
    }

    public function testAssertWhereUsingBackedEnum(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => BackedEnum::Test->value,
        ]);

        $assert->where('bar', BackedEnum::Test);

        $assert = AssertableJson::fromArray([
            'bar' => BackedEnum::TestEmpty->value,
        ]);

        $assert->where('bar', BackedEnum::TestEmpty);
    }

    public function testAssertWhereFailsUsingBackedEnum(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => BackedEnum::Test->value,
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] does not match the expected value.');

        $assert->where('bar', BackedEnum::TestEmpty);
    }

    public function testAssertWhereNullMatchesValue(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => null,
        ]);

        $assert->whereNull('bar');
    }

    public function testAssertWhereNullFailsWhenNotNull(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'value',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] should be null.');

        $assert->whereNull('bar');
    }

    public function testAssertWhereNullFailsWhenMissing(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'value',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [baz] does not exist.');

        $assert->whereNull('baz');
    }

    public function testAssertWhereNotNullMatchesValue(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'value',
        ]);

        $assert->whereNotNull('bar');
    }

    public function testAssertWhereNotNullFailsWhenNull(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => null,
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] should not be null.');

        $assert->whereNotNull('bar');
    }

    public function testAssertWhereNotNullFailsWhenMissing(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'value',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [baz] does not exist.');

        $assert->whereNotNull('baz');
    }

    public function testAssertWhereContainsFailsWithEmptyValue(): void
    {
        $assert = AssertableJson::fromArray([]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo] does not contain [1].');

        $assert->whereContains('foo', ['1']);
    }

    public function testAssertWhereContainsFailsWithMissingValue(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => ['bar', 'baz'],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo] does not contain [invalid].');

        $assert->whereContains('foo', ['bar', 'baz', 'invalid']);
    }

    public function testAssertWhereContainsFailsWithMissingNestedValue(): void
    {
        $assert = AssertableJson::fromArray([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
            ['id' => 4],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [id] does not contain [5].');

        $assert->whereContains('id', [1, 2, 3, 4, 5]);
    }

    public function testAssertWhereContainsFailsWhenDoesNotMatchType(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [1, 2, 3, 4],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo] does not contain [1].');

        $assert->whereContains('foo', ['1']);
    }

    public function testAssertWhereContainsFailsWhenDoesNotSatisfyClosure(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [1, 2, 3, 4],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo] does not contain a value that passes the truth test within the given closure.');

        $assert->whereContains('foo', [function ($actual) {
            return $actual === 5;
        }]);
    }

    public function testAssertWhereContainsFailsWhenHavingExpectedValueButDoesNotSatisfyClosure(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [1, 2, 3, 4],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo] does not contain a value that passes the truth test within the given closure.');

        $assert->whereContains('foo', [1, function ($actual) {
            return $actual === 5;
        }]);
    }

    public function testAssertWhereContainsFailsWhenSatisfiesClosureButDoesNotHaveExpectedValue(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [1, 2, 3, 4],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo] does not contain [5].');

        $assert->whereContains('foo', [5, function ($actual) {
            return $actual === 1;
        }]);
    }

    public function testAssertWhereContainsWithNestedValue(): void
    {
        $assert = AssertableJson::fromArray([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
            ['id' => 4],
        ]);

        $assert->whereContains('id', 1);
        $assert->whereContains('id', [1, 2, 3, 4]);
        $assert->whereContains('id', [4, 3, 2, 1]);
    }

    public function testAssertWhereContainsWithMatchingType(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [1, 2, 3, 4],
        ]);

        $assert->whereContains('foo', 1);
        $assert->whereContains('foo', [1]);
    }

    public function testAssertWhereContainsWithNullValue(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => null,
        ]);

        $assert->whereContains('foo', null);
        $assert->whereContains('foo', [null]);
    }

    public function testAssertWhereContainsWithOutOfOrderMatchingType(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [4, 1, 7, 3],
        ]);

        $assert->whereContains('foo', [1, 7, 4, 3]);
    }

    public function testAssertWhereContainsWithOutOfOrderNestedMatchingType(): void
    {
        $assert = AssertableJson::fromArray([
            ['bar' => 5],
            ['baz' => 4],
            ['zal' => 8],
        ]);

        $assert->whereContains('baz', 4);
    }

    public function testAssertWhereContainsWithClosure(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [1, 2, 3, 4],
        ]);

        $assert->whereContains('foo', function ($actual) {
            return $actual % 3 === 0;
        });
    }

    public function testAssertWhereContainsWithNestedClosure(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 1,
            'bar' => 2,
            'baz' => 3,
        ]);

        $assert->whereContains('baz', function ($actual) {
            return $actual % 3 === 0;
        });
    }

    public function testAssertWhereContainsWithMultipleClosure(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [1, 2, 3, 4],
        ]);

        $assert->whereContains('foo', [
            function ($actual) {
                return $actual % 3 === 0;
            },
            function ($actual) {
                return $actual % 2 === 0;
            },
        ]);
    }

    public function testAssertWhereContainsWithNullExpectation(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 1,
        ]);

        $assert->whereContains('foo', null);
    }

    public function testAssertWhereContainsUsingBackedEnum(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [BackedEnum::Test->value],
        ]);

        $assert->whereContains('bar', BackedEnum::Test);

        $assert = AssertableJson::fromArray([
            'bar' => [BackedEnum::TestEmpty->value],
        ]);

        $assert->whereContains('bar', BackedEnum::TestEmpty);
    }

    public function testAssertWhereContainsFailsUsingBackedEnum(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [BackedEnum::TestEmpty->value],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] does not contain [test].');

        $assert->whereContains('bar', BackedEnum::Test);
    }

    public function testAssertNestedWhereMatchesValue(): void
    {
        $assert = AssertableJson::fromArray([
            'example' => [
                'nested' => 'nested-value',
            ],
        ]);

        $assert->where('example.nested', 'nested-value');
    }

    public function testAssertNestedWhereFailsWhenDoesNotMatchValue(): void
    {
        $assert = AssertableJson::fromArray([
            'example' => [
                'nested' => 'nested-value',
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [example.nested] does not match the expected value.');

        $assert->where('example.nested', 'another-value');
    }

    public function testAssertNestedWhereUsingBackedEnum(): void
    {
        $assert = AssertableJson::fromArray([
            'example' => [
                'nested' => BackedEnum::Test->value,
            ],
        ]);

        $assert->where('example.nested', BackedEnum::Test);
    }

    public function testAssertNestedWhereFailsUsingBackedEnum(): void
    {
        $assert = AssertableJson::fromArray([
            'example' => [
                'nested' => BackedEnum::TestEmpty->value,
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [example.nested] does not match the expected value.');

        $assert->where('example.nested', BackedEnum::Test);
    }

    public function testAssertWhereDoesNotMatchValue(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'value',
        ]);

        $assert->whereNot('bar', 'different_value');
    }

    public function testAssertWhereNotFailsWhenMatchingValue(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'value',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] contains a value that should be missing: [bar, value]');

        $assert->whereNot('bar', 'value');
    }

    public function testAssertWhereNotFailsWhenNotMissing(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'value',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [baz] does not exist.');

        $assert->whereNot('baz', 'value');
    }

    public function testAssertWhereNotUsingClosure(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'baz',
        ]);

        $assert->whereNot('bar', function ($value) {
            return $value === 'foo';
        });
    }

    public function testAssertWhereNotFailsWhenMatchesValueUsingClosure(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'baz',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] was marked as invalid using a closure.');

        $assert->whereNot('bar', function ($value) {
            return $value === 'baz';
        });
    }

    public function testAssertWhereNotUsingBackedEnum(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => BackedEnum::Test->value,
        ]);

        $assert->whereNot('bar', BackedEnum::TestEmpty);
    }

    public function testAssertWhereNotFailsUsingBackedEnum(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => BackedEnum::Test->value,
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] contains a value that should be missing: [bar, test]');

        $assert->whereNot('bar', BackedEnum::Test);
    }

    public function testScope(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => 'example',
                'prop' => 'value',
            ],
        ]);

        $called = false;
        $assert->has('bar', function (AssertableJson $assert) use (&$called) {
            $called = true;
            $assert
                ->where('baz', 'example')
                ->where('prop', 'value');
        });

        $this->assertTrue($called, 'The scoped query was never actually called.');
    }

    public function testScopeFailsWhenPropMissing(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => 'example',
                'prop' => 'value',
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [baz] does not exist.');

        $assert->has('baz', function (AssertableJson $item) {
            $item->where('baz', 'example');
        });
    }

    public function testScopeFailsWhenPropSingleValue(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => 'value',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] is not scopeable.');

        $assert->has('bar', function (AssertableJson $item) {
        });
    }

    public function testScopeShorthand(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                ['key' => 'first'],
                ['key' => 'second'],
            ],
        ]);

        $called = false;
        $assert->has('bar', 2, function (AssertableJson $item) use (&$called) {
            $item->where('key', 'first');
            $called = true;
        });

        $this->assertTrue($called, 'The scoped query was never actually called.');
    }

    public function testScopeShorthandWithoutCount(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                ['key' => 'first'],
                ['key' => 'second'],
            ],
        ]);

        $called = false;
        $assert->has('bar', null, function (AssertableJson $item) use (&$called) {
            $item->where('key', 'first');
            $called = true;
        });

        $this->assertTrue($called, 'The scoped query was never actually called.');
    }

    public function testScopeShorthandFailsWhenAssertingZeroItems(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                ['key' => 'first'],
                ['key' => 'second'],
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] does not have the expected size.');

        $assert->has('bar', 0, function (AssertableJson $item) {
            $item->where('key', 'first');
        });
    }

    public function testScopeShorthandFailsWhenAmountOfItemsDoesNotMatch(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                ['key' => 'first'],
                ['key' => 'second'],
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [bar] does not have the expected size.');

        $assert->has('bar', 1, function (AssertableJson $item) {
            $item->where('key', 'first');
        });
    }

    public function testScopeShorthandFailsWhenAssertingEmptyArray(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(
            'Cannot scope directly onto the first element of property [bar] because it is empty.'
        );

        $assert->has('bar', 0, function (AssertableJson $item) {
            $item->where('key', 'first');
        });
    }

    public function testScopeShorthandFailsWhenAssertingEmptyArrayWithoutCount(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(
            'Cannot scope directly onto the first element of property [bar] because it is empty.'
        );

        $assert->has('bar', null, function (AssertableJson $item) {
            $item->where('key', 'first');
        });
    }

    public function testScopeShorthandFailsWhenSecondArgumentUnsupportedType(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                ['key' => 'first'],
                ['key' => 'second'],
            ],
        ]);

        $this->expectException(TypeError::class);

        $assert->has('bar', 'invalid', function (AssertableJson $item) {
            $item->where('key', 'first');
        });
    }

    public function testFirstScope(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [
                'key' => 'first',
            ],
            'bar' => [
                'key' => 'second',
            ],
        ]);

        $assert->first(function (AssertableJson $item) {
            $item->where('key', 'first');
        });
    }

    public function testFirstScopeFailsWhenNoProps(): void
    {
        $assert = AssertableJson::fromArray([]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Cannot scope directly onto the first element of the root level because it is empty.');

        $assert->first(function (AssertableJson $item) {
        });
    }

    public function testFirstNestedScopeFailsWhenNoProps(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Cannot scope directly onto the first element of property [foo] because it is empty.');

        $assert->has('foo', function (AssertableJson $assert) {
            $assert->first(function (AssertableJson $item) {
            });
        });
    }

    public function testFirstScopeFailsWhenPropSingleValue(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 'bar',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo] is not scopeable.');

        $assert->first(function (AssertableJson $item) {
        });
    }

    public function testEachScope(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [
                'key' => 'first',
            ],
            'bar' => [
                'key' => 'second',
            ],
        ]);

        $assert->each(function (AssertableJson $item) {
            $item->whereType('key', 'string');
        });
    }

    public function testEachScopeFailsWhenNoProps(): void
    {
        $assert = AssertableJson::fromArray([]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Cannot scope directly onto each element of the root level because it is empty.');

        $assert->each(function (AssertableJson $item) {
        });
    }

    public function testEachNestedScopeFailsWhenNoProps(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Cannot scope directly onto each element of property [foo] because it is empty.');

        $assert->has('foo', function (AssertableJson $assert) {
            $assert->each(function (AssertableJson $item) {
            });
        });
    }

    public function testEachScopeFailsWhenPropSingleValue(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 'bar',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo] is not scopeable.');

        $assert->each(function (AssertableJson $item) {
        });
    }

    public function testFailsWhenNotInteractingWithAllPropsInScope(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => 'example',
                'prop' => 'value',
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Unexpected properties were found in scope [bar].');

        $assert->has('bar', function (AssertableJson $item) {
            $item->where('baz', 'example');
        });
    }

    public function testDisableInteractionCheckForCurrentScope(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => 'example',
                'prop' => 'value',
            ],
        ]);

        $assert->has('bar', function (AssertableJson $item) {
            $item->etc();
        });
    }

    public function testCannotDisableInteractionCheckForDifferentScopes(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => [
                    'foo' => 'bar',
                    'example' => 'value',
                ],
                'prop' => 'value',
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Unexpected properties were found in scope [bar.baz].');

        $assert->has('bar', function (AssertableJson $item) {
            $item
                ->etc()
                ->has('baz', function (AssertableJson $item) {
                });
        });
    }

    public function testTopLevelPropInteractionDisabledByDefault(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 'bar',
            'bar' => 'baz',
        ]);

        $assert->has('foo');
    }

    public function testTopLevelInteractionEnabledWhenInteractedFlagSet(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 'bar',
            'bar' => 'baz',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Unexpected properties were found on the root level.');

        $assert
            ->has('foo')
            ->interacted();
    }

    public function testAssertWhereAllMatchesValues(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [
                'bar' => 'value',
                'example' => ['hello' => 'world'],
            ],
            'baz' => 'another',
        ]);

        $assert->whereAll([
            'foo.bar' => 'value',
            'foo.example' => ArrayableStubObject::make(['hello' => 'world']),
            'baz' => function ($value) {
                return $value === 'another';
            },
        ]);
    }

    public function testAssertWhereAllFailsWhenAtLeastOnePropDoesNotMatchValue(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 'bar',
            'baz' => 'example',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [baz] was marked as invalid using a closure.');

        $assert->whereAll([
            'foo' => 'bar',
            'baz' => function ($value) {
                return $value === 'foo';
            },
        ]);
    }

    public function testAssertWhereTypeString(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 'bar',
        ]);

        $assert->whereType('foo', 'string');
    }

    public function testAssertWhereTypeInteger(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 123,
        ]);

        $assert->whereType('foo', 'integer');
    }

    public function testAssertWhereTypeBoolean(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => true,
        ]);

        $assert->whereType('foo', 'boolean');
    }

    public function testAssertWhereTypeDouble(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 12.3,
        ]);

        $assert->whereType('foo', 'double');
    }

    public function testAssertWhereTypeArray(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => ['bar', 'baz'],
            'bar' => ['foo' => 'baz'],
        ]);

        $assert->whereType('foo', 'array');
        $assert->whereType('bar', 'array');
    }

    public function testAssertWhereTypeNull(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => null,
        ]);

        $assert->whereType('foo', 'null');
    }

    public function testAssertWhereAllType(): void
    {
        $assert = AssertableJson::fromArray([
            'one' => 'foo',
            'two' => 123,
            'three' => true,
            'four' => 12.3,
            'five' => ['foo', 'bar'],
            'six' => ['foo' => 'bar'],
            'seven' => null,
        ]);

        $assert->whereAllType([
            'one' => 'string',
            'two' => 'integer',
            'three' => 'boolean',
            'four' => 'double',
            'five' => 'array',
            'six' => 'array',
            'seven' => 'null',
        ]);
    }

    public function testAssertWhereTypeWhenWrongTypeIsGiven(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 'bar',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo] is not of expected type [integer].');

        $assert->whereType('foo', 'integer');
    }

    public function testAssertWhereTypeWithUnionTypes(): void
    {
        $firstAssert = AssertableJson::fromArray([
            'foo' => 'bar',
        ]);

        $secondAssert = AssertableJson::fromArray([
            'foo' => null,
        ]);

        $firstAssert->whereType('foo', ['string', 'null']);
        $secondAssert->whereType('foo', ['string', 'null']);
    }

    public function testAssertWhereTypeWhenWrongUnionTypeIsGiven(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 123,
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo] is not of expected type [string|null].');

        $assert->whereType('foo', ['string', 'null']);
    }

    public function testAssertWhereTypeWithPipeInUnionType(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 'bar',
        ]);

        $assert->whereType('foo', 'string|null');
    }

    public function testAssertWhereTypeWithPipeInWrongUnionType(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => 'bar',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo] is not of expected type [integer|null].');

        $assert->whereType('foo', 'integer|null');
    }

    public function testAssertHasAll(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [
                'bar' => 'value',
                'example' => ['hello' => 'world'],
            ],
            'baz' => 'another',
        ]);

        $assert->hasAll([
            'foo.bar',
            'foo.example',
            'baz',
        ]);
    }

    public function testAssertHasAllFailsWhenAtLeastOnePropMissing(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [
                'bar' => 'value',
                'example' => ['hello' => 'world'],
            ],
            'baz' => 'another',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo.baz] does not exist.');

        $assert->hasAll([
            'foo.bar',
            'foo.baz',
            'baz',
        ]);
    }

    public function testAssertHasAllAcceptsMultipleArgumentsInsteadOfArray(): void
    {
        $assert = AssertableJson::fromArray([
            'foo' => [
                'bar' => 'value',
                'example' => ['hello' => 'world'],
            ],
            'baz' => 'another',
        ]);

        $assert->hasAll('foo.bar', 'foo.example', 'baz');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [foo.baz] does not exist.');

        $assert->hasAll('foo.bar', 'foo.baz', 'baz');
    }

    public function testAssertCountMultipleProps(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'key' => 'value',
                'prop' => 'example',
            ],
            'baz' => [
                'another' => 'value',
            ],
        ]);

        $assert->hasAll([
            'bar' => 2,
            'baz' => 1,
        ]);
    }

    public function testAssertCountMultiplePropsFailsWhenPropMissing(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'key' => 'value',
                'prop' => 'example',
            ],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Property [baz] does not exist.');

        $assert->hasAll([
            'bar' => 2,
            'baz' => 1,
        ]);
    }

    public function testMacroable(): void
    {
        AssertableJson::macro('myCustomMacro', function () {
            throw new RuntimeException('My Custom Macro was called!');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('My Custom Macro was called!');

        $assert = AssertableJson::fromArray(['foo' => 'bar']);
        $assert->myCustomMacro();
    }

    public function testTappable(): void
    {
        $assert = AssertableJson::fromArray([
            'bar' => [
                'baz' => 'example',
                'prop' => 'value',
            ],
        ]);

        $called = false;
        $assert->has('bar', function (AssertableJson $assert) use (&$called) {
            $assert->etc();
            $assert->tap(function (AssertableJson $assert) use (&$called) {
                $called = true;
            });
        });

        $this->assertTrue($called, 'The scoped query was never actually called.');
    }
}
