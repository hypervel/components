<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\TransformationTest;

use DateTime;
use DateTimeInterface;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\Hidden;
use Hypervel\Data\Attributes\WithTransformer;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\MaxTransformationDepthReached;
use Hypervel\Data\Lazy;
use Hypervel\Data\Optional;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Data\Transformers\DateTimeInterfaceTransformer;
use Hypervel\Data\Transformers\Transformer;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\CircData;
use Hypervel\Tests\Data\Fixtures\EnumData;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\Transformers\ConfidentialDataCollectionTransformer;
use Hypervel\Tests\Data\Fixtures\Transformers\ConfidentialDataTransformer;
use Hypervel\Tests\Data\Fixtures\Transformers\StringToUpperTransformer;
use Hypervel\Tests\Data\Fixtures\UlarData;
use stdClass;

class TransformationTest extends TestCase
{
    /**
     * Get package providers for the transformation test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanTransformADataObject(): void
    {
        $data = new SimpleData('Ruben');

        $this->assertSame([
            'string' => 'Ruben',
        ], $data->toArray());
    }

    public function testCanTransformACollectionOfDataObjects(): void
    {
        $collection = SimpleData::collect(collect([
            'Ruben',
            'Freek',
            'Brent',
        ]), DataCollection::class);

        $this->assertSame([
            ['string' => 'Ruben'],
            ['string' => 'Freek'],
            ['string' => 'Brent'],
        ], $collection->toArray());
    }

    public function testWillUseGlobalTransformersToConvertSpecificTypes(): void
    {
        $date = new DateTime('16 may 1994');

        $data = new class($date) extends Data {
            /**
             * Create a data object with a date.
             */
            public function __construct(public DateTime $date)
            {
            }
        };

        $this->assertSame(['date' => '1994-05-16T00:00:00+00:00'], $data->toArray());
    }

    public function testCanUseAManuallySpecifiedTransformer(): void
    {
        $date = new DateTime('16 may 1994');

        $data = new class($date) extends Data {
            /**
             * Create a data object with a date.
             */
            public function __construct(
                #[WithTransformer(DateTimeInterfaceTransformer::class, 'd-m-Y')]
                public DateTime $date
            ) {
            }
        };

        $this->assertSame(['date' => '16-05-1994'], $data->toArray());
    }

    public function testATransformerWillNeverHandleANullValue(): void
    {
        $data = new class(null) extends Data {
            /**
             * Create a data object with an optional date.
             */
            public function __construct(
                #[WithTransformer(DateTimeInterfaceTransformer::class, 'd-m-Y')]
                public ?DateTime $date
            ) {
            }
        };

        $this->assertSame(['date' => null], $data->toArray());
    }

    public function testCanGetTheDataObjectWithoutTransforming(): void
    {
        $lazyData = new SimpleData('Lazy');

        $data = new class($dataObject = new SimpleData('Test'), $dataCollection = new DataCollection(SimpleData::class, ['A', 'B']), Lazy::create(fn (): SimpleData => $lazyData), 'Test', $transformable = new DateTime('16 may 1994')) extends Data {
            /**
             * Create a data object with nested, lazy and transformable values.
             */
            public function __construct(
                public SimpleData $data,
                #[DataCollectionOf(SimpleData::class)]
                public DataCollection $dataCollection,
                public Lazy|Data $lazy,
                public string $string,
                public DateTime $transformable
            ) {
            }
        };

        $this->assertSame([
            'data' => $dataObject,
            'dataCollection' => $dataCollection,
            'string' => 'Test',
            'transformable' => $transformable,
        ], $data->all());

        $this->assertSame([
            'data' => $dataObject,
            'dataCollection' => $dataCollection,
            'lazy' => $lazyData,
            'string' => 'Test',
            'transformable' => $transformable,
        ], $data->include('lazy')->all());
    }

    public function testCanTransformToJson(): void
    {
        $this->assertSame('{"string":"Hello"}', SimpleData::from('Hello')->toJson());
        $this->assertSame('{"string":"Hello"}', json_encode(SimpleData::from('Hello')));
    }

    public function testCanUseACustomTransformerForADataObjectAndOrDataCollectable(): void
    {
        $nestedData = new class(42, 'Hello World') extends Data {
            /**
             * Create a data object with an integer and a string.
             */
            public function __construct(
                public int $integer,
                public string $string,
            ) {
            }
        };

        $nestedDataCollection = $nestedData::collect([
            ['integer' => 314, 'string' => 'pi'],
            ['integer' => '69', 'string' => 'Hypervel after hours'],
        ]);

        $dataWithDefaultTransformers = new class($nestedData, $nestedDataCollection) extends Data {
            /**
             * Create a data object with a nested object and collection.
             */
            public function __construct(
                public Data $nestedData,
                #[DataCollectionOf(SimpleData::class)]
                public array $nestedDataCollection,
            ) {
            }
        };

        $dataWithSpecificTransformers = new class($nestedData, $nestedDataCollection) extends Data {
            /**
             * Create a data object with custom-transformed nested values.
             */
            public function __construct(
                #[WithTransformer(ConfidentialDataTransformer::class)]
                public Data $nestedData,
                #[WithTransformer(ConfidentialDataCollectionTransformer::class),
                    DataCollectionOf(SimpleData::class)]
                public array $nestedDataCollection,
            ) {
            }
        };

        $this->assertSame([
            'nestedData' => ['integer' => 42, 'string' => 'Hello World'],
            'nestedDataCollection' => [
                ['integer' => 314, 'string' => 'pi'],
                ['integer' => 69, 'string' => 'Hypervel after hours'],
            ],
        ], $dataWithDefaultTransformers->toArray());

        $this->assertSame([
            'nestedData' => ['integer' => 'CONFIDENTIAL', 'string' => 'CONFIDENTIAL'],
            'nestedDataCollection' => [
                ['integer' => 'CONFIDENTIAL', 'string' => 'CONFIDENTIAL'],
                ['integer' => 'CONFIDENTIAL', 'string' => 'CONFIDENTIAL'],
            ],
        ], $dataWithSpecificTransformers->toArray());
    }

    public function testCanTransformBuiltItTypesWithCustomTransformers(): void
    {
        $data = new class('Hello World', 'Hello World') extends Data {
            /**
             * Create a data object with plain and transformed strings.
             */
            public function __construct(
                public string $without_transformer,
                #[WithTransformer(StringToUpperTransformer::class)]
                public string $with_transformer
            ) {
            }
        };

        $this->assertSame([
            'without_transformer' => 'Hello World',
            'with_transformer' => 'HELLO WORLD',
        ], $data->toArray());
    }

    public function testWillNotTransformOptionalValues(): void
    {
        $dataClass = new class('', Optional::create(), Optional::create()) extends Data {
            /**
             * Create a data object with optional strings.
             */
            public function __construct(
                public string $string,
                public string|Optional $undefinable_string,
                #[WithTransformer(StringToUpperTransformer::class)]
                public string|Optional $undefinable_string_with_transformer,
            ) {
            }
        };

        $partialData = $dataClass::from([
            'string' => 'Hello World',
        ]);

        $fullData = $dataClass::from([
            'string' => 'Hello World',
            'undefinable_string' => 'Hello World',
            'undefinable_string_with_transformer' => 'Hello World',
        ]);

        $this->assertSame([
            'string' => 'Hello World',
        ], $partialData->toArray());

        $this->assertSame([
            'string' => 'Hello World',
            'undefinable_string' => 'Hello World',
            'undefinable_string_with_transformer' => 'HELLO WORLD',
        ], $fullData->toArray());
    }

    public function testWillTransformNativeEnums(): void
    {
        $data = EnumData::from([
            'enum' => DummyBackedEnum::FOO,
        ]);

        $this->assertSame([
            'enum' => 'foo',
        ], $data->toArray());
        $this->assertSame([
            'enum' => DummyBackedEnum::FOO,
        ], $data->all());
    }

    public function testCanHaveACircularDependencyWhichWillNotGoIntoAnInfiniteLoop(): void
    {
        $data = CircData::from([
            'string' => 'Hello World',
            'ular' => [
                'string' => 'Hello World',
                'circ' => [
                    'string' => 'Hello World',
                ],
            ],
        ]);

        $this->assertEquals(
            new CircData('Hello World', new UlarData('Hello World', new CircData('Hello World', null))),
            $data,
        );

        $this->assertSame([
            'string' => 'Hello World',
            'ular' => [
                'string' => 'Hello World',
                'circ' => [
                    'string' => 'Hello World',
                    'ular' => null,
                ],
            ],
        ], $data->toArray());
    }

    public function testCanHaveAHiddenValue(): void
    {
        $dataObject = new class('', '') extends Data {
            /**
             * Create a data object with a shown and a hidden value.
             */
            public function __construct(
                public string $show,
                #[Hidden]
                public string $hidden,
            ) {
            }
        };

        $created = $dataObject::from(['show' => 'Yes', 'hidden' => 'No']);

        $this->assertSame('Yes', $created->show);
        $this->assertSame('No', $created->hidden);

        $validated = $dataObject::validateAndCreate(['show' => 'Yes', 'hidden' => 'No']);

        $this->assertSame('Yes', $validated->show);
        $this->assertSame('No', $validated->hidden);

        $this->assertSame(['show' => 'Yes'], $dataObject::from(['show' => 'Yes', 'hidden' => 'No'])->toArray());
    }

    public function testIsPossibleToAddExtraGlobalTransformersWhenTransformingUsingContext(): void
    {
        $dataClass = new class extends Data {
            public DateTime $dateTime;
        };

        $data = $dataClass::from([
            'dateTime' => new DateTime,
        ]);

        $customTransformer = new class implements Transformer {
            /**
             * Transform every value to a fixed string.
             */
            public function transform(DataProperty $property, mixed $value, TransformationContext $context): string
            {
                return 'Custom transformed date';
            }
        };

        $transformed = $data->transform(
            TransformationContextFactory::create()->withTransformer(DateTimeInterface::class, $customTransformer)
        );

        $this->assertSame([
            'dateTime' => 'Custom transformed date',
        ], $transformed);
    }

    public function testCanTransformAPaginatedDataCollection(): void
    {
        $items = Collection::times(100, fn (int $index): string => "Item {$index}");

        $paginator = new LengthAwarePaginator(
            $items->forPage(1, 15),
            100,
            15
        );

        $collection = new PaginatedDataCollection(SimpleData::class, $paginator);

        $this->assertInstanceOf(PaginatedDataCollection::class, $collection);

        $output = $collection->toArray();

        $this->assertSame(['data', 'links', 'meta'], array_keys($output));

        $this->assertCount(15, $output['data']);
        $this->assertSame(['string' => 'Item 1'], $output['data'][0]);
        $this->assertSame(['string' => 'Item 15'], $output['data'][14]);

        $this->assertCount(9, $output['links']);

        $this->assertSame([
            'current_page' => 1,
            'first_page_url' => '/?page=1',
            'from' => 1,
            'last_page' => 7,
            'last_page_url' => '/?page=7',
            'next_page_url' => '/?page=2',
            'path' => '/',
            'per_page' => 15,
            'prev_page_url' => null,
            'to' => 15,
            'total' => 100,
        ], $output['meta']);
    }

    public function testCanTransformAPaginatedCursorDataCollection(): void
    {
        $items = Collection::times(100, fn (int $index): string => "Item {$index}");

        $paginator = new CursorPaginator(
            $items,
            15,
        );

        $collection = new CursorPaginatedDataCollection(SimpleData::class, $paginator);

        $this->assertInstanceOf(CursorPaginatedDataCollection::class, $collection);
        $this->assertJsonStringEqualsJsonString(json_encode([
            'data' => array_map(fn (int $index): array => ['string' => "Item {$index}"], range(1, 15)),
            'links' => [],
            'meta' => [
                'path' => '/',
                'per_page' => 15,
                'next_cursor' => 'eyJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9',
                'next_page_url' => '/?cursor=eyJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9',
                'prev_cursor' => null,
                'prev_page_url' => null,
            ],
        ]), $collection->toJson());
    }

    // REMOVED: 'can transform a data collection'; DataCollection::through() is deprecated upstream, so use toCollection().

    public function testCanTransformADataCollectionIntoJson(): void
    {
        $collection = (new DataCollection(SimpleData::class, ['A', 'B', 'C']));

        $this->assertSame('[{"string":"A"},{"string":"B"},{"string":"C"}]', $collection->toJson());
        $this->assertSame('[{"string":"A"},{"string":"B"},{"string":"C"}]', json_encode($collection));
    }

    public function testCanTransformATypedIterableWithACustomTransformer(): void
    {
        $dataClass = new class extends Data {
            /** @var array<string> */
            public array $array;
        };

        $transformed = $dataClass::from(['array' => ['a', 'b', 'c']])->transform(
            TransformationContextFactory::create()
                ->withTransformer('string', StringToUpperTransformer::class)
        );

        $this->assertSame(['array' => ['A', 'B', 'C']], $transformed);
    }

    public function testDoesNotTransformATypedIterableWithACustomTransformerWhenAUnionTypeIsUsedWithANonIterableValue(): void
    {
        $dataClass = new class extends Data {
            /** @var array<string>|string */
            public string|array $array;
        };

        $transformed = $dataClass::from(['array' => 'a'])->transform(
            TransformationContextFactory::create()
                ->withTransformer('string', StringToUpperTransformer::class)
        );

        $this->assertSame(['array' => 'A'], $transformed);
    }

    public function testItPossibleToSetTheMaxTransformationDepthWhenTransformingObjects(): void
    {
        $a = new stdClass;
        $b = new stdClass;

        $a->b = $b;
        $b->a = $a;

        $this->assertSame([
            'dataA' => [
                'dataB' => [
                    'dataA' => [
                        'dataB' => [],
                    ],
                ],
            ],
        ], TestMaxDataObjectTransformationDepthB::fromOther($a)->transform(
            TransformationContextFactory::create()->maxDepth(4, throw: false)
        ));

        $this->expectException(MaxTransformationDepthReached::class);
        $this->expectExceptionMessageIs('Max transformation depth of 4 reached.');

        TestMaxDataObjectTransformationDepthB::fromOther($a)->transform(
            TransformationContextFactory::create()->maxDepth(4)
        );
    }

    public function testItPossibleToSetTheMaxTransformationDepthWhenTransformingCollections(): void
    {
        $a = new stdClass;
        $b = new stdClass;

        $a->b = $b;
        $b->a = $a;

        $this->assertSame([
            'cb' => [
                [
                    'ca' => [
                        [
                            'cb' => [
                                ['ca' => []],
                            ],
                        ],
                    ],
                ],
            ],
        ], TestMaxDatCollectionTransformationDepthB::fromOther($a)->transform(
            TransformationContextFactory::create()->maxDepth(4, throw: false)
        ));

        $this->expectException(MaxTransformationDepthReached::class);
        $this->expectExceptionMessageIs('Max transformation depth of 4 reached.');

        TestMaxDatCollectionTransformationDepthB::fromOther($a)->transform(
            TransformationContextFactory::create()->maxDepth(4)
        );
    }
}

class TestMaxDataObjectTransformationDepthA extends Data
{
    /**
     * Create a data object that always includes its lazy counterpart.
     */
    public function __construct(
        public Lazy|TestMaxDataObjectTransformationDepthB $dataB
    ) {
        $this->includePermanently('dataB');
    }

    /**
     * Create the data object from one side of a circular object graph.
     */
    public static function fromOther(stdClass $b): self
    {
        return new self(Lazy::create(fn (): TestMaxDataObjectTransformationDepthB => TestMaxDataObjectTransformationDepthB::from($b->a)));
    }
}

class TestMaxDataObjectTransformationDepthB extends Data
{
    /**
     * Create a data object that always includes its lazy counterpart.
     */
    public function __construct(
        public Lazy|TestMaxDataObjectTransformationDepthA $dataA
    ) {
        $this->includePermanently('dataA');
    }

    /**
     * Create the data object from one side of a circular object graph.
     */
    public static function fromOther(stdClass $a): self
    {
        return new self(Lazy::create(fn (): TestMaxDataObjectTransformationDepthA => TestMaxDataObjectTransformationDepthA::from($a->b)));
    }
}

class TestMaxDatCollectionTransformationDepthA extends Data
{
    /**
     * Create a data object that always includes its lazy collection.
     */
    public function __construct(
        #[DataCollectionOf(TestMaxDatCollectionTransformationDepthB::class)]
        public Lazy|DataCollection $ca
    ) {
        $this->includePermanently('ca');
    }

    /**
     * Create the data object from one side of a circular object graph.
     */
    public static function fromOther(stdClass $b): self
    {
        return new self(Lazy::create(fn (): array => TestMaxDatCollectionTransformationDepthB::collect([$b->a])));
    }
}

class TestMaxDatCollectionTransformationDepthB extends Data
{
    /**
     * Create a data object that always includes its lazy collection.
     */
    public function __construct(
        #[DataCollectionOf(TestMaxDatCollectionTransformationDepthA::class)]
        public Lazy|DataCollection $cb
    ) {
        $this->includePermanently('cb');
    }

    /**
     * Create the data object from one side of a circular object graph.
     */
    public static function fromOther(stdClass $a): self
    {
        return new self(Lazy::create(fn (): array => TestMaxDatCollectionTransformationDepthA::collect([$a->b])));
    }
}
