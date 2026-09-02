<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Creation;

use ArrayIterator;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Pagination\Paginator as PaginatorContract;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\LoadRelation;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use stdClass;

class DataCollectTest extends TestCase
{
    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCollectsArraysAndExplicitDataCollectionsWithKeysPreserved(): void
    {
        $source = [
            'first' => ['id' => '1'],
            'second' => new RootCollectData(2),
        ];

        $array = RootCollectData::collect($source);
        $collection = RootCollectData::collect($source, DataCollection::class);

        $this->assertSame(['first', 'second'], array_keys($array));
        $this->assertSame(1, $array['first']->id);
        $this->assertSame($source['second'], $array['second']);
        $this->assertInstanceOf(DataCollection::class, $collection);
        $this->assertSame(['first', 'second'], array_keys($collection->items()));
    }

    public function testCollectPreservesOrdinaryCollectionShapeAndDowngradesEloquentCollections(): void
    {
        $collection = RootCollectData::collect(new RootCollectSourceCollection([
            ['id' => '1'],
        ]));
        $eloquent = RootCollectData::collect(new EloquentCollection([
            ['id' => '2'],
        ]));

        $this->assertInstanceOf(RootCollectSourceCollection::class, $collection);
        $this->assertSame(1, $collection->first()->id);
        $this->assertInstanceOf(Collection::class, $eloquent);
        $this->assertNotInstanceOf(EloquentCollection::class, $eloquent);
        $this->assertSame(2, $eloquent->first()->id);
    }

    public function testCollectClonesPaginatorMetadataWithoutMutatingTheCaller(): void
    {
        $source = new Paginator(
            ['first' => ['id' => '1']],
            15,
            2,
            ['path' => '/items', 'fragment' => 'results'],
        );

        $result = RootCollectData::collect($source);

        $this->assertInstanceOf(Paginator::class, $result);
        $this->assertNotSame($source, $result);
        $this->assertSame(['id' => '1'], $source->items()['first']);
        $this->assertSame(1, $result->items()['first']->id);
        $this->assertSame(2, $result->currentPage());
        $this->assertSame('/items', $result->path());
        $this->assertSame('results', $result->fragment());
    }

    public function testCollectPreservesLazyTraversalWithoutValidation(): void
    {
        $evaluated = false;
        $source = LazyCollection::make(function () use (&$evaluated): iterable {
            $evaluated = true;

            yield 'first' => ['id' => '1'];
        });

        $result = RootCollectData::collect($source);

        $this->assertInstanceOf(LazyCollection::class, $result);
        $this->assertFalse($evaluated);
        $this->assertSame(1, $result->first()->id);
        $this->assertTrue($evaluated);
    }

    public function testCollectRunsOneRootValidationLifecycle(): void
    {
        $prepareCalls = 0;
        $beforeValidationCalls = 0;
        $withValidatorCalls = 0;
        $afterValidationCalls = 0;

        $result = RootCollectData::factory()
            ->alwaysValidate()
            ->prepareData(function (array $payload) use (&$prepareCalls): array {
                ++$prepareCalls;

                return $payload;
            })
            ->beforeValidation(function (array $payload) use (&$beforeValidationCalls): array {
                ++$beforeValidationCalls;

                return $payload;
            })
            ->withValidator(function () use (&$withValidatorCalls): void {
                ++$withValidatorCalls;
            })
            ->afterValidation(function (array $payload) use (&$afterValidationCalls): array {
                ++$afterValidationCalls;

                return $payload;
            })
            ->collect([
                ['id' => '1'],
                ['id' => '2'],
            ]);

        $this->assertSame(2, $prepareCalls);
        $this->assertSame(1, $beforeValidationCalls);
        $this->assertSame(1, $withValidatorCalls);
        $this->assertSame(1, $afterValidationCalls);
        $this->assertSame([1, 2], array_column($result, 'id'));
    }

    public function testCollectMethodsReceiveTheNormalizedSourceShape(): void
    {
        $result = NamedRootCollectData::collect(new Collection([
            ['id' => '1'],
        ]));
        $array = NamedRootCollectData::collect(new Collection([
            ['id' => '2'],
        ]), 'array');

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame(11, $result->first()->id);
        $this->assertIsArray($array);
        $this->assertSame(2, $array[0]->id);
    }

    public function testExactEloquentCollectMethodDoesNotMatchDowngradedSource(): void
    {
        $result = EloquentNamedRootCollectData::collect(new EloquentCollection([
            ['id' => '1'],
        ]));

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertNotInstanceOf(EloquentCollection::class, $result);
        $this->assertSame(1, $result->first()->id);
    }

    public function testCollectBuildsExplicitPaginatorWrappersWithoutMutatingTheSource(): void
    {
        $paginator = new Paginator(
            [['id' => '1']],
            15,
            2,
            ['path' => '/items'],
        );
        $cursorPaginator = new CursorPaginator(
            [['id' => '2']],
            15,
            null,
            ['path' => '/cursor-items'],
        );

        $paginated = RootCollectData::collect($paginator, PaginatedDataCollection::class);
        $cursorPaginated = RootCollectData::collect(
            $cursorPaginator,
            CursorPaginatedDataCollection::class,
        );

        $this->assertInstanceOf(PaginatedDataCollection::class, $paginated);
        $this->assertInstanceOf(CursorPaginatedDataCollection::class, $cursorPaginated);
        $this->assertSame(['id' => '1'], $paginator->items()[0]);
        $this->assertSame(['id' => '2'], $cursorPaginator->items()[0]);
        $this->assertSame(1, $paginated->items()->items()[0]->id);
        $this->assertSame(2, $cursorPaginated->items()->items()[0]->id);
        $this->assertSame(2, $paginated->items()->currentPage());
        $this->assertSame('/cursor-items', $cursorPaginated->items()->path());
    }

    public function testContractOnlyPaginatorCanTargetANonPaginatorWithoutCollectMethodDispatch(): void
    {
        $paginator = m::mock(PaginatorContract::class);
        $paginator->shouldReceive('items')->once()->andReturn([
            ['id' => '1'],
        ]);

        $result = NamedRootCollectData::collect($paginator, 'array');

        $this->assertIsArray($result);
        $this->assertSame(1, $result[0]->id);
    }

    public function testTraversableCanTargetANonSourceShapedCollectionWithoutCollectMethodDispatch(): void
    {
        $result = NamedRootCollectData::collect(
            new ArrayIterator([['id' => '1']]),
            'array',
        );

        $this->assertIsArray($result);
        $this->assertSame(1, $result[0]->id);
    }

    public function testCollectBatchesExplicitModelRelationsBeforeItemNormalization(): void
    {
        $first = (new RootCollectModel)->setRawAttributes(['id' => 1]);
        $second = (new RootCollectModel)->setRawAttributes(['id' => 2]);
        $models = new RootCollectModelCollection([$first, $second]);

        $result = RootCollectModelData::collect($models, DataCollection::class);

        $this->assertSame(['profile'], $models->loadedRelations);
        $this->assertSame(1, $models->loadMissingCount);
        $this->assertSame(0, $first->loadMissingCount);
        $this->assertSame(0, $second->loadMissingCount);
        $this->assertSame(1, $result[0]->profile->modelId);
        $this->assertSame(2, $result[1]->profile->modelId);
    }

    public function testNestedEloquentCollectionsBatchRelationsBeforeChildNormalization(): void
    {
        $child = (new RootCollectModel)->setRawAttributes(['id' => 3]);
        $children = new RootCollectModelCollection([$child]);
        $parent = new RootCollectParentModel;
        $parent->setRelation('children', $children);

        $data = RootCollectParentData::from($parent);

        $this->assertSame(['profile'], $children->loadedRelations);
        $this->assertSame(1, $children->loadMissingCount);
        $this->assertSame(0, $child->loadMissingCount);
        $this->assertSame(3, $data->children->first()->profile->modelId);
    }
}

class RootCollectModelData extends Data
{
    public function __construct(
        public int $id,
        #[LoadRelation]
        public object $profile,
    ) {
    }
}

class RootCollectParentData extends Data
{
    public function __construct(
        #[DataCollectionOf(RootCollectModelData::class)]
        public Collection $children,
    ) {
    }
}

class RootCollectModel extends Model
{
    public int $loadMissingCount = 0;

    /**
     * Determine if the fixture profile relation exists.
     */
    public function isRelation(string $key): bool
    {
        return $key === 'profile';
    }

    /**
     * Fail when collection creation falls back to per-model relation loading.
     */
    public function loadMissing(array|string $relations): static
    {
        ++$this->loadMissingCount;

        return parent::loadMissing($relations);
    }
}

class RootCollectParentModel extends Model
{
}

/** @extends EloquentCollection<int, RootCollectModel> */
class RootCollectModelCollection extends EloquentCollection
{
    public int $loadMissingCount = 0;

    /** @var list<string> */
    public array $loadedRelations = [];

    /**
     * Load fixture relations for every model in one collection operation.
     */
    public function loadMissing(array|string $relations): static
    {
        ++$this->loadMissingCount;
        $this->loadedRelations = is_array($relations) ? $relations : [$relations];

        foreach ($this as $model) {
            $profile = new stdClass;
            $profile->modelId = $model->getAttribute('id');
            $model->setRelation('profile', $profile);
        }

        return $this;
    }
}

class RootCollectData extends Data
{
    public function __construct(public int $id)
    {
    }
}

class NamedRootCollectData extends RootCollectData
{
    /**
     * Customize collection construction.
     *
     * @param Collection<array-key, static> $items
     * @return Collection<array-key, static>
     */
    public static function collectCollection(Collection $items): Collection
    {
        return $items->map(
            static fn (self $item): self => new self($item->id + 10),
        );
    }
}

class EloquentNamedRootCollectData extends RootCollectData
{
    /**
     * Customize Eloquent collection construction.
     *
     * @param EloquentCollection<array-key, static> $items
     * @return Collection<array-key, static>
     */
    public static function collectEloquent(EloquentCollection $items): Collection
    {
        return $items->map(
            static fn (self $item): self => new self($item->id + 10),
        );
    }
}

class RootCollectSourceCollection extends Collection
{
}
