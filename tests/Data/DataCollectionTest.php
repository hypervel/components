<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Exceptions\PaginatedCollectionIsAlwaysWrapped;
use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Collections\CustomCollection;
use Hypervel\Tests\Data\Fixtures\DummyDto;
use Hypervel\Tests\Data\Fixtures\LazyData;
use Hypervel\Tests\Data\Fixtures\MultiLazyData;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use RuntimeException;

class DataCollectionTest extends TestCase
{
    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    // REMOVED: 'can filter a collection' and 'can reject items within a collection'; DataCollection's filter() and reject() are deprecated upstream, so use toCollection().

    public function testItCanPutItemsThroughAPaginatedDataCollection(): void
    {
        $collection = new PaginatedDataCollection(
            SimpleData::class,
            new LengthAwarePaginator(['A', 'B'], 2, 15),
        );

        $filtered = $collection->through(fn (SimpleData $data): SimpleData => new SimpleData("{$data->string}x"))->toArray();

        $this->assertSame([
            ['string' => 'Ax'],
            ['string' => 'Bx'],
        ], $filtered['data']);
    }

    public function testIsIteratable(): void
    {
        $collection = new DataCollection(SimpleData::class, [
            'A', 'B', 'C', 'D',
        ]);

        $letters = [];

        foreach ($collection as $item) {
            $letters[] = $item->string;
        }

        $this->assertSame(['A', 'B', 'C', 'D'], $letters);
    }

    public function testHasArrayAccess(): void
    {
        $collection = SimpleData::collect([
            'A', 'B', SimpleData::from('C'), SimpleData::from('D'),
        ], DataCollection::class);

        // Count
        $this->assertCount(4, $collection);

        // Offset exists
        $this->assertNotEmpty($collection[3]);
        $this->assertTrue(empty($collection[5]));

        // Offset get
        $this->assertEquals(SimpleData::from('A'), $collection[0]);
        $this->assertEquals(SimpleData::from('D'), $collection[3]);

        // Offset set
        $collection[2] = 'And now something completely different';
        $collection[4] = 'E';

        $this->assertEquals(SimpleData::from('And now something completely different'), $collection[2]);
        $this->assertEquals(SimpleData::from('E'), $collection[4]);

        // Offset unset
        unset($collection[4]);

        $this->assertCount(4, $collection);
    }

    public function testCanUpdateDataPropertiesWithinACollection(): void
    {
        LazyData::setAllowedIncludes(null);

        $collection = new DataCollection(LazyData::class, [
            LazyData::from('Never gonna give you up!'),
        ]);

        $this->assertSame([
            ['name' => 'Never gonna give you up!'],
        ], $collection->include('name')->toArray());

        $collection[0]->name = 'Giving Up on Love';

        $this->assertSame([
            ['name' => 'Giving Up on Love'],
        ], $collection->include('name')->toArray());

        $collection[] = LazyData::from('Cry for help');

        $this->assertSame([
            ['name' => 'Giving Up on Love'],
            ['name' => 'Cry for help'],
        ], $collection->include('name')->toArray());

        unset($collection[0]);

        $this->assertSame([
            1 => ['name' => 'Cry for help'],
        ], $collection->include('name')->toArray());
    }

    public function testCanCreateADataCollectionFromALazyCollection(): void
    {
        $lazyCollection = new LazyCollection(function (): iterable {
            $items = [
                'Never gonna give you up!',
                'Giving Up on Love',
            ];

            foreach ($items as $item) {
                yield $item;
            }
        });

        $collection = new DataCollection(SimpleData::class, $lazyCollection);

        $this->assertEquals([
            SimpleData::from('Never gonna give you up!'),
            SimpleData::from('Giving Up on Love'),
        ], $collection->items());

        // REMOVED: upstream's following through()->filter() chain; both proxies are deprecated upstream, so use toCollection().
    }

    public function testCanConvertADataCollectionIntoAHypervelCollection(): void
    {
        $this->assertEquals(
            collect([
                SimpleData::from('A'),
                SimpleData::from('B'),
                SimpleData::from('C'),
            ]),
            (new DataCollection(SimpleData::class, ['A', 'B', 'C']))->toCollection(),
        );
    }

    // REMOVED: 'can reset the keys' and the two 'can return a sole data object' cases; DataCollection's values() and sole() are deprecated upstream, so use toCollection().

    public function testACollectionCanBeMerged(): void
    {
        $collectionA = SimpleData::collect(collect(['A', 'B']));
        $collectionB = SimpleData::collect(collect(['C', 'D']));

        $this->assertSame([
            ['string' => 'A'],
            ['string' => 'B'],
            ['string' => 'C'],
            ['string' => 'D'],
        ], $collectionA->merge($collectionB)->toArray());
    }

    public function testCanUseACustomCollectionExtendedFromCollectionToCollectACollectionOfDataObjects(): void
    {
        $collection = SimpleData::collect(new CustomCollection([
            ['string' => 'A'],
            ['string' => 'B'],
        ]));

        $this->assertInstanceOf(CustomCollection::class, $collection);
        $this->assertInstanceOf(SimpleData::class, $collection[0]);
        $this->assertInstanceOf(SimpleData::class, $collection[1]);
    }

    public function testDoesNotMutateWrappedPaginatorsDuringTransformation(): void
    {
        $paginatorOfSimpleData = new Paginator([
            ['string' => 'A'],
            ['string' => 'B'],
        ], perPage: 15);

        $collection = SimpleData::collect($paginatorOfSimpleData, PaginatedDataCollection::class);

        $expect = [
            ['string' => 'A'],
            ['string' => 'B'],
        ];

        // Perform the transformation twice, the second should not throw
        $this->assertSame($expect, $collection->toArray()['data']);
        $this->assertSame($expect, $collection->toArray()['data']);
    }

    public function testItCanIncludeLazyItemsThroughAPaginatedDataCollection(): void
    {
        // https://github.com/spatie/laravel-data/issues/746
        $collection = new PaginatedDataCollection(
            MultiLazyData::class,
            new LengthAwarePaginator([
                DummyDto::rick(),
                DummyDto::bon(),
            ], 2, 15),
        );

        $filtered = $collection->through(fn (MultiLazyData $data): MultiLazyData => $data->include('artist'))->toArray();

        $this->assertSame([
            ['artist' => 'Rick Astley'],
            ['artist' => 'Bon Jovi'],
        ], $filtered['data']);
    }

    public function testConstructorUsesOneRootOperationAndRetainsNamedItemFactories(): void
    {
        CollectionNormalizerData::$normalizerCalls = 0;

        $normalized = new DataCollection(CollectionNormalizerData::class, ['1', '2']);
        $named = new DataCollection(CollectionNamedFactoryData::class, ['3', '4']);

        $this->assertSame(1, CollectionNormalizerData::$normalizerCalls);
        $this->assertSame([1, 2], array_column($normalized->items(), 'id'));
        $this->assertSame([3, 4], array_column($named->items(), 'id'));
    }

    public function testConstructorDefersLazyItemsAndSharesTheirOperationMemo(): void
    {
        CollectionNormalizerData::$normalizerCalls = 0;
        $evaluated = false;
        $source = LazyCollection::make(function () use (&$evaluated): iterable {
            $evaluated = true;

            yield 'first' => '1';
            yield 'second' => '2';
        });

        $collection = new DataCollection(CollectionNormalizerData::class, $source);

        $this->assertFalse($evaluated);
        $this->assertSame(0, CollectionNormalizerData::$normalizerCalls);
        $this->assertSame(1, $collection->toCollection()->first()->id);
        $this->assertTrue($evaluated);
        $this->assertSame(1, CollectionNormalizerData::$normalizerCalls);
        $this->assertSame(2, $collection->toCollection()->last()->id);
        $this->assertSame(1, CollectionNormalizerData::$normalizerCalls);
    }

    public function testConstructorAndOffsetSetBypassAnOverriddenPublicFromMethod(): void
    {
        $collection = new DataCollection(CollectionOverriddenFromData::class, [
            'first' => ['id' => '1'],
        ]);

        $collection['second'] = ['id' => '2'];

        $this->assertSame(1, $collection['first']->id);
        $this->assertSame(2, $collection['second']->id);
    }

    public function testKeyedAndIteratorReadsCopyPartialsWithoutConsumingThem(): void
    {
        $first = new CollectionPartialData(1, 'first');
        $second = new CollectionPartialData(2, 'second');
        $collection = new DataCollection(CollectionPartialData::class, ['a' => $first, 'b' => $second]);
        $collection->only('name');

        $this->assertSame($first, $collection['a']);
        $this->assertSame(['name' => 'first'], $first->toArray());
        $this->assertSame(['id' => 1, 'name' => 'first'], $first->toArray());

        $this->assertSame(['a' => $first, 'b' => $second], iterator_to_array($collection));
        $this->assertSame(['name' => 'second'], $second->toArray());

        $this->assertSame(['a' => ['name' => 'first'], 'b' => ['name' => 'second']], $collection->toArray());
        $this->assertSame([
            'a' => ['id' => 1, 'name' => 'first'],
            'b' => ['id' => 2, 'name' => 'second'],
        ], $collection->toArray());
    }

    public function testRepeatedReadsAndAllCallsCopyPartialsOnce(): void
    {
        $item = new CollectionPartialData(1, 'first');
        $collection = new DataCollection(CollectionPartialData::class, [$item]);
        $collection->onlyPermanently('name');

        $this->assertSame($item, $collection[0]);
        $this->assertSame($item, $collection[0]);
        $this->assertSame([$item], iterator_to_array($collection));
        $this->assertSame([$item], $collection->all());

        $this->assertCount(1, $item->getPartialsDefinition()->resolve($item)['only']);
        $this->assertSame(['name' => 'first'], $item->toArray());
    }

    public function testPaginatedTransformationReadsItemsWithoutCopyingPartials(): void
    {
        $calls = 0;
        $collection = new PaginatedDataCollection(CollectionPartialData::class, new Paginator(
            [['id' => '1', 'name' => 'first'], ['id' => '2', 'name' => 'second']],
            15,
            1,
            ['path' => '/items'],
        ));
        $collection->onlyWhen('name', static function () use (&$calls): bool {
            ++$calls;

            return true;
        }, permanent: true);
        [$first, $second] = $collection->items()->items();

        $this->assertSame([['name' => 'first'], ['name' => 'second']], $collection->toArray()['data']);
        $this->assertSame(1, $calls);
        $this->assertFalse($first->hasPartialsDefinition());
        $this->assertFalse($second->hasPartialsDefinition());

        $this->assertSame([$first, $second], iterator_to_array($collection));
        $this->assertSame(2, $calls);
        $this->assertSame(['name' => 'second'], $second->toArray());
    }

    public function testPaginatedCollectionOwnsItsPaginatorAndThroughReturnsAnIndependentClone(): void
    {
        $source = new Paginator(
            [['id' => '1', 'name' => 'first']],
            15,
            2,
            ['path' => '/items'],
        );
        $collection = new PaginatedDataCollection(CollectionPartialData::class, $source);
        $mapped = $collection->through(
            static fn (CollectionPartialData $data): CollectionPartialData => new CollectionPartialData(
                $data->id + 10,
                strtoupper($data->name),
            ),
        );

        $this->assertSame([['id' => '1', 'name' => 'first']], $source->items());
        $this->assertNotSame($source, $collection->items());
        $this->assertNotSame($collection->items(), $mapped->items());
        $this->assertSame(1, $collection->items()->items()[0]->id);
        $this->assertSame(11, $mapped->items()->items()[0]->id);
        $this->assertSame(2, $mapped->items()->currentPage());
        $this->assertSame('/items', $mapped->items()->path());
        $this->assertSame([['id' => 1, 'name' => 'first']], $collection->toArray()['data']);
        $this->assertSame(2, $collection->toArray()['meta']['current_page']);
        $this->assertSame('/items', $collection->toArray()['meta']['path']);
        $this->assertCount(1, $collection);

        $this->expectException(PaginatedCollectionIsAlwaysWrapped::class);

        $collection->withoutWrapping();
    }

    public function testCursorPaginatedCollectionOwnsAndTransformsItsPaginator(): void
    {
        $source = new CursorPaginator(
            [['id' => '1', 'name' => 'first']],
            15,
            null,
            ['path' => '/items'],
        );
        $collection = new CursorPaginatedDataCollection(CollectionPartialData::class, $source);

        $this->assertSame([['id' => '1', 'name' => 'first']], $source->items());
        $this->assertNotSame($source, $collection->items());
        $this->assertSame(1, $collection->items()->items()[0]->id);
        $this->assertSame('/items', $collection->items()->path());
        $this->assertSame([['id' => 1, 'name' => 'first']], $collection->toArray()['data']);
        $this->assertSame('/items', $collection->toArray()['meta']['path']);
        $this->assertSame(15, $collection->toArray()['meta']['per_page']);
        $this->assertSame([1], array_column(iterator_to_array($collection), 'id'));
    }

    public function testNonTransformableDtoItemsRemainRawAcrossCollectionShapes(): void
    {
        $collection = new DataCollection(CollectionDto::class, [['id' => 1]]);
        $dto = $collection[0];

        $this->assertSame([$dto], $collection->toArray());
        $this->assertSame([$dto], $collection->all());
        $this->assertSame('[{"id":1}]', $collection->toJson());
        $this->assertSame([], (new DataCollection(CollectionDto::class, []))->toArray());

        $paginated = new PaginatedDataCollection(
            CollectionDto::class,
            new Paginator([['id' => 2]], 15, 2, ['path' => '/paginated']),
        );
        $paginatedDto = $paginated->items()->items()[0];
        $paginatedOutput = $paginated->toArray();

        $this->assertSame($paginatedDto, $paginatedOutput['data'][0]);
        $this->assertSame(2, $paginatedOutput['meta']['current_page']);
        $this->assertSame('/paginated', $paginatedOutput['meta']['path']);

        $cursorPaginated = new CursorPaginatedDataCollection(
            CollectionDto::class,
            new CursorPaginator([['id' => 3]], 15, null, ['path' => '/cursor']),
        );
        $cursorDto = $cursorPaginated->items()->items()[0];
        $cursorOutput = $cursorPaginated->toArray();

        $this->assertSame($cursorDto, $cursorOutput['data'][0]);
        $this->assertSame('/cursor', $cursorOutput['meta']['path']);
        $this->assertSame(15, $cursorOutput['meta']['per_page']);
    }
}

class CollectionDto extends Dto
{
    public function __construct(public int $id)
    {
    }
}

class CollectionNormalizerData extends Data
{
    public static int $normalizerCalls = 0;

    public function __construct(public int $id)
    {
    }

    /**
     * Get class-owned normalizers.
     */
    public static function normalizers(): array
    {
        ++self::$normalizerCalls;

        return [CollectionStringNormalizer::class];
    }
}

class CollectionStringNormalizer implements Normalizer
{
    /**
     * Normalize a scalar identifier.
     */
    public function normalize(mixed $value): array|Normalized|null
    {
        return is_string($value) ? ['id' => $value] : null;
    }
}

class CollectionNamedFactoryData extends Data
{
    public function __construct(public int $id)
    {
    }

    /**
     * Create data from a scalar identifier.
     */
    public static function fromString(string $id): static
    {
        return new static((int) $id);
    }
}

class CollectionOverriddenFromData extends Data
{
    public function __construct(public int $id)
    {
    }

    /**
     * Fail when collection internals reenter the public entry point.
     */
    public static function from(mixed ...$payloads): static
    {
        throw new RuntimeException('Collection internals must not call public from().');
    }
}

class CollectionPartialData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}
