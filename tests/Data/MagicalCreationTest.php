<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\MagicalCreationTest;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\CannotCreateDataCollectable;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\DataCollections\CustomDataCollection;
use Hypervel\Tests\Data\Fixtures\DataWithMultipleArgumentCreationMethod;
use Hypervel\Tests\Data\Fixtures\DummyDto;
use Hypervel\Tests\Data\Fixtures\EnumData;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\Models\DummyModel;
use Hypervel\Tests\Data\Fixtures\Models\DummyModelWithCasts;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use PHPUnit\Framework\Attributes\DataProvider;
use SplObjectStorage;

class MagicalCreationTest extends TestCase
{
    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanCreateDataUsingAMagicalMethod(): void
    {
        $data = new class('') extends Data {
            /**
             * Create the data object from its string.
             */
            public function __construct(public string $string)
            {
            }

            /**
             * Create the data object from a string.
             */
            public static function fromString(string $string): static
            {
                return new self($string);
            }

            /**
             * Create the data object from a song transfer object.
             */
            public static function fromDto(DummyDto $dto): static
            {
                return new self($dto->artist);
            }

            /**
             * Create the data object from an array payload.
             */
            public static function fromArray(array $payload): static
            {
                return new self($payload['string']);
            }
        };

        $this->assertEquals(new $data('Hello World'), $data::from('Hello World'));
        $this->assertEquals(new $data('Rick Astley'), $data::from(DummyDto::rick()));
        $this->assertEquals(new $data('Bon Jovi'), $data::from(DummyDto::bon()));
        $this->assertEquals(new $data('Hello World'), $data::from(['string' => 'Hello World']));
        $this->assertEquals(new $data('Hello World'), $data::from(DummyModelWithCasts::make(['string' => 'Hello World'])));
    }

    public function testCanMagicallyCreateADataObject(): void
    {
        $dataClass = new class('', '') extends Data {
            /**
             * Create the data object from two values.
             */
            public function __construct(
                public mixed $propertyA,
                public mixed $propertyB,
            ) {
            }

            /**
             * Create the data object from strings with a default second value.
             */
            public static function fromStringWithDefault(string $a, string $b = 'World'): static
            {
                return new self($a, $b);
            }

            /**
             * Create the data object from two integers.
             */
            public static function fromIntsWithDefault(int $a, int $b): static
            {
                return new self($a, $b);
            }

            /**
             * Create the data object from a simple data object.
             */
            public static function fromSimpleData(SimpleData $data): static
            {
                return new self($data->string, $data->string);
            }

            /**
             * Create the data object from any data object.
             */
            public static function fromData(Data $data): static
            {
                return new self('data', json_encode($data));
            }
        };

        $this->assertEquals(new $dataClass('Hello', 'World'), $dataClass::from('Hello'));
        $this->assertEquals(new $dataClass('Hello', 'World'), $dataClass::from('Hello', 'World'));
        $this->assertEquals(new $dataClass(42, 69), $dataClass::from(42, 69));
        $this->assertEquals(new $dataClass('Hello', 'Hello'), $dataClass::from(SimpleData::from('Hello')));
        $this->assertEquals(new $dataClass('data', '{"enum":"foo"}'), $dataClass::from(new EnumData(DummyBackedEnum::FOO)));
    }

    public function testCanCreateDataUsingAMagicalMethodWithTheInterfaceOfTheValueAsType(): void
    {
        $data = new class('') extends Data {
            /**
             * Create the data object from its string.
             */
            public function __construct(public string $string)
            {
            }

            /**
             * Create the data object from an arrayable value.
             */
            public static function fromInterface(Arrayable $arrayable): static
            {
                return new self($arrayable->toArray()['string']);
            }
        };

        $interfaceable = new class implements Arrayable {
            /**
             * Get the value as an array.
             */
            public function toArray(): array
            {
                return [
                    'string' => 'Rick Astley',
                ];
            }
        };

        $this->assertEquals(new $data('Rick Astley'), $data::from($interfaceable));
    }

    public function testCanCreateDataUsingAMagicalMethodWithTheBaseClassOfTheValueAsType(): void
    {
        $data = new class('') extends Data {
            /**
             * Create the data object from its string.
             */
            public function __construct(public string $string)
            {
            }

            /**
             * Create the data object from any model.
             */
            public static function fromModel(Model $model): static
            {
                return new self($model->string);
            }
        };

        $inherited = new DummyModel(['string' => 'Rick Astley']);

        $this->assertEquals(new $data('Rick Astley'), $data::from($inherited));
    }

    public function testCanCreateDataFromAMagicalMethodWithMultipleParameters(): void
    {
        $this->assertEquals(
            new DataWithMultipleArgumentCreationMethod('Rick Astley_42'),
            DataWithMultipleArgumentCreationMethod::from('Rick Astley', 42),
        );
    }

    public function testCanInjectTheCreationContextWhenUsingAMagicalMethod(): void
    {
        $dataClass = new class extends Data {
            /**
             * Create the data object from its string.
             */
            public function __construct(
                public string $string = 'something'
            ) {
            }

            /**
             * Create the data object from a prefix and the creation context.
             */
            public static function fromArray(string $prefix, CreationContext $context): static
            {
                return new self("{$prefix} {$context->dataClass}");
            }
        };

        $this->assertSame('Hi there ' . $dataClass::class, $dataClass::from('Hi there')->string);
    }

    public function testWillUseMagicMethodsWhenCreatingACollectionOfDataObjects(): void
    {
        $dataClass = new class('') extends Data {
            /**
             * Create the data object from its string.
             */
            public function __construct(public string $otherString)
            {
            }

            /**
             * Create the data object from a simple data object.
             */
            public static function fromSimpleData(SimpleData $simpleData): static
            {
                return new self($simpleData->string);
            }
        };

        $collection = new DataCollection($dataClass::class, [
            SimpleData::from('A'),
            SimpleData::from('B'),
        ]);

        $this->assertInstanceOf($dataClass::class, $collection[0]);
        $this->assertEquals('A', $collection[0]->otherString);
        $this->assertInstanceOf($dataClass::class, $collection[1]);
        $this->assertEquals('B', $collection[1]->otherString);
    }

    public function testCanMagicallyCollectData(): void
    {
        $dataClass = new class extends Data {
            public string $string;

            /**
             * Create the data object from a string.
             */
            public static function fromString(string $string): self
            {
                $data = new self;

                $data->string = $string;

                return $data;
            }

            /**
             * Collect an array into a custom collection.
             */
            public static function collectArray(array $items): TestSomeCustomCollection
            {
                return new TestSomeCustomCollection($items);
            }

            /**
             * Collect a collection into an array.
             */
            public static function collectCollection(Collection $items): array
            {
                return $items->all();
            }

            /**
             * Collect a length-aware paginator into a cursor paginator.
             */
            public static function collectPaginator(LengthAwarePaginator $items): CursorPaginator
            {
                return new CursorPaginator($items->all(), $items->perPage());
            }

            /**
             * Collect a cursor paginator into a length-aware paginator.
             */
            public static function collectCursorPaginator(CursorPaginator $items): LengthAwarePaginator
            {
                return new LengthAwarePaginator($items->all(), $items->count(), $items->perPage());
            }
        };

        $collected = $dataClass::collect(['a', 'b', 'c']);

        $this->assertInstanceOf(TestSomeCustomCollection::class, $collected);
        $this->assertEquals([
            $dataClass::from('a'),
            $dataClass::from('b'),
            $dataClass::from('c'),
        ], $collected->all());

        $this->assertEquals([
            $dataClass::from('a'),
            $dataClass::from('b'),
            $dataClass::from('c'),
        ], $dataClass::collect(new Collection(['a', 'b', 'c'])));

        $this->assertEquals([
            $dataClass::from('a'),
            $dataClass::from('b'),
            $dataClass::from('c'),
        ], $dataClass::collect(new TestSomeCustomCollection(['a', 'b', 'c'])));

        $this->assertInstanceOf(CursorPaginator::class, $dataClass::collect(new LengthAwarePaginator(['a', 'b', 'c'], 3, 15)));
        $this->assertInstanceOf(LengthAwarePaginator::class, $dataClass::collect(new CursorPaginator(['a', 'b', 'c'], 15)));
    }

    public function testCanDisableMagicallyCollectingData(): void
    {
        $dataClass = new class('') extends SimpleData {
            /**
             * Collect an array into a collection.
             */
            public static function collectArray(array $items): Collection
            {
                return new Collection($items);
            }
        };

        $this->assertEquals([
            new $dataClass('a'),
            new $dataClass('b'),
            new $dataClass('c'),
        ], $dataClass::factory()->withoutMagicalCreation()->collect([
            ['string' => 'a'],
            ['string' => 'b'],
            ['string' => 'c'],
        ]));
    }

    public function testCanDisableSpecificMagicCollectingDataMethods(): void
    {
        $dataClass = new class('') extends SimpleData {
            /**
             * Collect an array into a collection.
             */
            public static function collectArray(array $items): Collection
            {
                return new Collection($items);
            }
        };

        $collected = $dataClass::collect(['a', 'b', 'c']);

        $this->assertInstanceOf(Collection::class, $collected);
        $this->assertEquals([
            new $dataClass('a'),
            new $dataClass('b'),
            new $dataClass('c'),
        ], $collected->all());

        $this->assertEquals([
            new $dataClass('a'),
            new $dataClass('b'),
            new $dataClass('c'),
        ], $dataClass::factory()->ignoreMagicalMethod('collectArray')->collect([
            ['string' => 'a'],
            ['string' => 'b'],
            ['string' => 'c'],
        ]));
    }

    public function testCanInjectTheCreationContextWhenCollectingDataWithAMagicalMethod(): void
    {
        $dataClass = new class('') extends SimpleData {
            /**
             * Collect an array, appending the creation context's class to each string.
             */
            public static function collectArray(array $items, CreationContext $context): array
            {
                return array_map(fn (SimpleData $data): SimpleData => new SimpleData($data->string . ' ' . $context->dataClass), $items);
            }
        };

        $this->assertEquals([
            SimpleData::from('a ' . $dataClass::class),
            SimpleData::from('b ' . $dataClass::class),
            SimpleData::from('c ' . $dataClass::class),
        ], $dataClass::collect(['a', 'b', 'c']));
    }

    #[DataProvider('collectIntoTargets')]
    public function testCanUseAStringToCollectDataInto(string $into, Closure $expected): void
    {
        $this->assertEquals($expected(), SimpleData::collect(['A', 'B'], $into));
    }

    /**
     * Get the collection targets and the values collecting into them produces.
     */
    public static function collectIntoTargets(): iterable
    {
        yield 'array' => [
            'array',
            fn (): array => [
                SimpleData::from('A'),
                SimpleData::from('B'),
            ],
        ];

        yield 'hypervel collection' => [
            Collection::class,
            fn (): Collection => new Collection([
                SimpleData::from('A'),
                SimpleData::from('B'),
            ]),
        ];

        yield 'hypervel lazy collection' => [
            LazyCollection::class,
            fn (): LazyCollection => new LazyCollection([
                SimpleData::from('A'),
                SimpleData::from('B'),
            ]),
        ];

        yield 'data collection' => [
            DataCollection::class,
            fn (): DataCollection => new DataCollection(SimpleData::class, [
                SimpleData::from('A'),
                SimpleData::from('B'),
            ]),
        ];

        yield 'custom data collection' => [
            CustomDataCollection::class,
            fn (): CustomDataCollection => new CustomDataCollection(SimpleData::class, [
                SimpleData::from('A'),
                SimpleData::from('B'),
            ]),
        ];
    }

    #[DataProvider('paginatorTargets')]
    public function testCannotCollectAnArrayIntoAPaginatorTarget(string $into): void
    {
        // Upstream makes up a first page with a total of the item count and 15 per page. A paginator
        // target here must come from a paginator source so its real pagination details are kept.
        $this->expectException(CannotCreateDataCollectable::class);
        $this->expectExceptionMessageIsOrContains('from `array`');

        SimpleData::collect(['A', 'B'], $into);
    }

    /**
     * Get the paginator targets an array cannot be collected into.
     */
    public static function paginatorTargets(): iterable
    {
        yield 'data paginated collection' => [PaginatedDataCollection::class];
        yield 'data cursor paginated collection' => [CursorPaginatedDataCollection::class];
        yield 'paginator' => [LengthAwarePaginator::class];
        yield 'cursor paginator' => [CursorPaginator::class];
    }

    public function testCanSpecificallySelectTheCorrectCollectMethodUsingAnIntoReturnType(): void
    {
        $dataClass = new class('') extends SimpleData {
            /**
             * Collect an array, uppercasing each string.
             */
            public static function collectArray(array $items): array
            {
                return array_map(
                    fn (SimpleData $data): SimpleData => new SimpleData(strtoupper($data->string)),
                    $items
                );
            }

            /**
             * Collect an array into a collection, lowercasing each string.
             */
            public static function collectCollection(array $items): Collection
            {
                return new Collection(array_map(
                    fn (SimpleData $data): SimpleData => new SimpleData(strtolower($data->string)),
                    $items
                ));
            }
        };

        $this->assertEquals([SimpleData::from('HELLO'), SimpleData::from('WORLD')], $dataClass::collect(['Hello', 'World'], 'array'));

        $collected = $dataClass::collect(['Hello', 'World'], Collection::class);

        $this->assertInstanceOf(Collection::class, $collected);
        $this->assertEquals([SimpleData::from('hello'), SimpleData::from('world')], $collected->all());
    }

    public function testCanOnlyCollectArraysCollectionsPaginators(): void
    {
        $storage = new SplObjectStorage;

        // Any Traversable can be read for its items, but without an explicit target the source's own
        // shape must be rebuildable.
        $this->expectException(CannotCreateDataCollectable::class);
        $this->expectExceptionMessageIsOrContains('from `SplObjectStorage`');

        SimpleData::collect($storage);
    }
}

class TestSomeCustomCollection extends Collection
{
}
