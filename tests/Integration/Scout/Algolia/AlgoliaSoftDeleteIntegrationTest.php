<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Algolia;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Tests\Scout\Models\SoftDeleteSearchableModel;
use Throwable;

/**
 * Integration tests for Scout soft delete behavior with Algolia.
 */
class AlgoliaSoftDeleteIntegrationTest extends AlgoliaScoutIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set('scout.soft_delete', true);
    }

    protected function setUpInCoroutine(): void
    {
        parent::setUpInCoroutine();

        $this->configureSoftDeleteIndex();
    }

    /**
     * Configure the soft-delete index with the __soft_deleted facet so Scout's
     * soft-delete filter works. Mirrors what AlgoliaEngine::configureSoftDeleteFilter
     * would do on the settings side.
     */
    protected function configureSoftDeleteIndex(): void
    {
        $indexName = $this->prefixedIndexName('soft_deletable_searchable_models');

        $this->algolia->setSettings($indexName, [
            'attributesForFaceting' => ['filterOnly(__soft_deleted)'],
        ]);
    }

    public function testSoftDeletedModelsArePushedWithSoftDeleteMetadata(): void
    {
        $model = SoftDeleteSearchableModel::withoutSyncingToSearch(function () {
            return SoftDeleteSearchableModel::create(['title' => 'Active', 'body' => 'Content']);
        });

        $this->engine->update(new EloquentCollection([$model]));
        $hits = $this->pollSearch($model->searchableAs(), '', 1);

        $this->assertCount(1, $hits);
        $this->assertArrayHasKey('__soft_deleted', $hits[0]);
        $this->assertSame(0, $hits[0]['__soft_deleted']);

        SoftDeleteSearchableModel::withoutSyncingToSearch(function () use ($model): void {
            $model->delete();
        });
        $this->engine->update(new EloquentCollection([$model->fresh()]));
        $hits = $this->pollSearchFor(
            $model->searchableAs(),
            '',
            fn (array $h) => ($h[0]['__soft_deleted'] ?? null) === 1,
        );

        $this->assertSame(1, $hits[0]['__soft_deleted']);
    }

    /**
     * Poll an Algolia index until the search returns the expected hit count.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function pollSearch(string $index, string $query, int $expectedCount, int $timeoutMs = 10000): array
    {
        return $this->pollSearchFor(
            $index,
            $query,
            fn (array $hits) => count($hits) === $expectedCount,
            $timeoutMs,
        );
    }

    /**
     * Poll an Algolia index until the hits pass the given predicate, or timeout.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function pollSearchFor(string $index, string $query, callable $predicate, int $timeoutMs = 10000): array
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $hits = [];

        while (microtime(true) < $deadline) {
            try {
                $response = $this->algolia->searchSingleIndex($index, ['query' => $query]);
                $hits = $response['hits'] ?? [];

                if ($predicate($hits)) {
                    return $hits;
                }
            } catch (Throwable) {
                // Index may not exist yet — keep polling until timeout.
            }

            usleep(200_000);
        }

        return $hits;
    }
}
