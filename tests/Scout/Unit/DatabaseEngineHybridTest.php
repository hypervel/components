<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Pagination\Paginator;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Engines\DatabaseEngine;
use Hypervel\Tests\TestCase;

class DatabaseEngineHybridTest extends TestCase
{
    /**
     * Set up pagination defaults.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Paginator::currentPageResolver(fn (): int => 1);
        Paginator::currentPathResolver(fn (): string => '/');
    }

    public function testItFusesTextAndSemanticResultsUsingWeightedRrf(): void
    {
        $model = new DatabaseHybridModel;
        $textModels = $model->newCollection([
            new DatabaseHybridModel(['id' => 1, 'name' => 'Text first']),
            new DatabaseHybridModel(['id' => 2, 'name' => 'Both']),
        ]);
        $semanticModels = $model->newCollection([
            new DatabaseHybridModel(['id' => 2, 'name' => 'Both']),
            new DatabaseHybridModel(['id' => 3, 'name' => 'Semantic only']),
        ]);

        $results = $this->fuse(
            (new Builder($model, 'query'))->hybrid(),
            $textModels,
            $semanticModels
        );

        $this->assertSame([2, 1, 3], $results->modelKeys());
    }

    public function testHybridWeightsAffectRrfOrdering(): void
    {
        $model = new DatabaseHybridModel;
        $textModels = $model->newCollection([
            new DatabaseHybridModel(['id' => 1, 'name' => 'Text first']),
            new DatabaseHybridModel(['id' => 2, 'name' => 'Text second']),
        ]);
        $semanticModels = $model->newCollection([
            new DatabaseHybridModel(['id' => 3, 'name' => 'Semantic first']),
        ]);

        $results = $this->fuse(
            (new Builder($model, 'query'))->hybrid(textWeight: 1, semanticWeight: 10),
            $textModels,
            $semanticModels
        );

        $this->assertSame([3, 1, 2], $results->modelKeys());
    }

    public function testHybridPaginationFetchesAndSlicesTheRankedWindow(): void
    {
        $model = new DatabaseHybridModel;
        $engine = new class extends DatabaseEngine {
            public ?int $requestedWindow = null;

            /**
             * Use hybrid search without a database connection.
             */
            protected function shouldPerformHybridSearch(Builder $builder): bool
            {
                return true;
            }

            /**
             * Return the ranked fixture models.
             */
            protected function hybridSearchModels(Builder $builder, int $limit): Collection
            {
                $this->requestedWindow = $limit;

                return $builder->model->newCollection(array_map(
                    fn (int $id): DatabaseHybridModel => new DatabaseHybridModel(['id' => $id]),
                    range(1, 10)
                ));
            }
        };

        $results = $engine->paginateUsingDatabase(
            (new Builder($model, 'query'))->hybrid(),
            2,
            'page',
            2
        );

        $this->assertSame(1000, $engine->requestedWindow);
        $this->assertSame([3, 4], $results->getCollection()->modelKeys());
        $this->assertSame(10, $results->total());
    }

    public function testHybridPaginationResolvesDefaultPageAndPerPageValues(): void
    {
        Paginator::currentPageResolver(fn (string $pageName): int => $pageName === 'documents' ? 2 : 1);

        $model = new DatabaseHybridModel;
        $model->setPerPage(2);
        $engine = new class extends DatabaseEngine {
            /**
             * Use hybrid search without a database connection.
             */
            protected function shouldPerformHybridSearch(Builder $builder): bool
            {
                return true;
            }

            /**
             * Return the ranked fixture models.
             */
            protected function hybridSearchModels(Builder $builder, int $limit): Collection
            {
                return $builder->model->newCollection(array_map(
                    fn (int $id): DatabaseHybridModel => new DatabaseHybridModel(['id' => $id]),
                    range(1, 5)
                ));
            }
        };

        $results = $engine->paginateUsingDatabase(
            (new Builder($model, 'query'))->hybrid(),
            null,
            'documents',
            null
        );

        $this->assertSame(2, $results->currentPage());
        $this->assertSame(2, $results->perPage());
        $this->assertSame([3, 4], $results->getCollection()->modelKeys());
    }

    public function testHybridFusionUsesDatabaseIdentityInsteadOfACustomScoutKey(): void
    {
        $model = new class extends DatabaseHybridModel {
            /**
             * Return a shared external search key.
             */
            public function getScoutKey(): string
            {
                return 'shared';
            }
        };

        $results = $this->fuse(
            (new Builder($model, 'query'))->hybrid(),
            $model->newCollection([$model->newInstance(['id' => 1])]),
            $model->newCollection([$model->newInstance(['id' => 2])]),
        );

        $this->assertSame([1, 2], $results->modelKeys());
    }

    /**
     * Fuse the ranked model collections.
     */
    protected function fuse(Builder $builder, Collection $textModels, Collection $semanticModels): Collection
    {
        $engine = new class extends DatabaseEngine {
            /**
             * Expose the result fusion for testing.
             */
            public function fuse(Builder $builder, Collection $textModels, Collection $semanticModels): Collection
            {
                return $this->fuseSearchResults($builder, $textModels, $semanticModels);
            }
        };

        return $engine->fuse($builder, $textModels, $semanticModels);
    }
}

class DatabaseHybridModel extends Model
{
    protected array $guarded = [];

    /**
     * Get the model's search key.
     */
    public function getScoutKey(): mixed
    {
        return $this->getKey();
    }
}
