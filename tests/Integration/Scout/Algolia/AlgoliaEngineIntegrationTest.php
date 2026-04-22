<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Algolia;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Throwable;

/**
 * Integration tests for AlgoliaEngine core operations against a live index.
 */
class AlgoliaEngineIntegrationTest extends AlgoliaScoutIntegrationTestCase
{
    public function testUpdateIndexesModelsInAlgolia(): void
    {
        $model = SearchableModel::create(['title' => 'Test Document', 'body' => 'Content here']);

        $this->engine->update(new EloquentCollection([$model]));

        $hits = $this->pollSearch($model->searchableAs(), 'Test', expectedCount: 1);

        $this->assertCount(1, $hits);
        $this->assertSame('Test Document', $hits[0]['title']);
    }

    public function testUpdateWithMultipleModels(): void
    {
        $models = new EloquentCollection([
            SearchableModel::create(['title' => 'First', 'body' => 'Body 1']),
            SearchableModel::create(['title' => 'Second', 'body' => 'Body 2']),
            SearchableModel::create(['title' => 'Third', 'body' => 'Body 3']),
        ]);

        $this->engine->update($models);

        $hits = $this->pollSearch($models->first()->searchableAs(), '', expectedCount: 3);

        $this->assertCount(3, $hits);
    }

    public function testDeleteRemovesModelsFromAlgolia(): void
    {
        $model = SearchableModel::create(['title' => 'To Delete', 'body' => 'Content']);

        $this->engine->update(new EloquentCollection([$model]));
        $this->pollSearch($model->searchableAs(), 'Delete', expectedCount: 1);

        $this->engine->delete(new EloquentCollection([$model]));

        $hits = $this->pollSearch($model->searchableAs(), 'Delete', expectedCount: 0);
        $this->assertCount(0, $hits);
    }

    public function testFlushClearsEntireIndex(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        $models = SearchableModel::all();
        $this->engine->update($models);
        $this->pollSearch($models->first()->searchableAs(), '', expectedCount: 2);

        $this->engine->flush($models->first());

        $hits = $this->pollSearch($models->first()->searchableAs(), '', expectedCount: 0);
        $this->assertCount(0, $hits);
    }

    /**
     * Poll an Algolia index until the search returns the expected hit count.
     *
     * Algolia writes are eventually consistent — saveObjects returns quickly
     * but the index isn't immediately queryable. Poll until we see the
     * expected count or hit the timeout.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function pollSearch(string $index, string $query, int $expectedCount, int $timeoutMs = 10000): array
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $hits = [];

        while (microtime(true) < $deadline) {
            try {
                $response = $this->algolia->searchSingleIndex($index, ['query' => $query]);
                $hits = $response['hits'] ?? [];

                if (count($hits) === $expectedCount) {
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
