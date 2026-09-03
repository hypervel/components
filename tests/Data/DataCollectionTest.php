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
use Hypervel\Pagination\Paginator;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\TestCase;
use RuntimeException;

class DataCollectionTest extends TestCase
{
    // REMOVED: Deprecated Data::collection() and Enumerable forwarding tests; use collect() and toCollection().

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
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

    public function testKeyedAndIteratorReadsDoNotConsumeOrPropagatePartials(): void
    {
        $first = new CollectionPartialData(1, 'first');
        $second = new CollectionPartialData(2, 'second');
        $collection = new DataCollection(CollectionPartialData::class, [$first, $second]);
        $collection->include('name');

        $this->assertSame($first, $collection[0]);

        foreach ($collection as $item) {
            $this->assertTrue(in_array($item, [$first, $second], true));
        }

        $this->assertTrue($first->getPartialsDefinition()->isEmpty());
        $this->assertTrue($second->getPartialsDefinition()->isEmpty());
        $this->assertFalse($collection->getPartialsDefinition()->isEmpty());
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
        $this->assertSame(2, $collection->toArray()['current_page']);
        $this->assertSame('/items', $collection->toArray()['path']);
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
        $this->assertSame('/items', $collection->toArray()['path']);
        $this->assertSame(15, $collection->toArray()['per_page']);
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
        $this->assertSame(2, $paginatedOutput['current_page']);
        $this->assertSame('/paginated', $paginatedOutput['path']);

        $cursorPaginated = new CursorPaginatedDataCollection(
            CollectionDto::class,
            new CursorPaginator([['id' => 3]], 15, null, ['path' => '/cursor']),
        );
        $cursorDto = $cursorPaginated->items()->items()[0];
        $cursorOutput = $cursorPaginated->toArray();

        $this->assertSame($cursorDto, $cursorOutput['data'][0]);
        $this->assertSame('/cursor', $cursorOutput['path']);
        $this->assertSame(15, $cursorOutput['per_page']);
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
