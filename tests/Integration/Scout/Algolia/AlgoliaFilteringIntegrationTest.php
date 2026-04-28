<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Algolia;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Throwable;

/**
 * Integration tests for Algolia where/whereIn/whereNotIn filtering.
 */
class AlgoliaFilteringIntegrationTest extends AlgoliaScoutIntegrationTestCase
{
    protected function setUpInCoroutine(): void
    {
        parent::setUpInCoroutine();

        $this->configureFilterableIndex();
    }

    /**
     * Configure attributesForFaceting so Algolia accepts numericFilters
     * on id/title/body. Required before indexing data.
     */
    protected function configureFilterableIndex(): void
    {
        $indexName = $this->prefixedIndexName('searchable_models');

        $this->algolia->setSettings($indexName, [
            'attributesForFaceting' => ['filterOnly(id)', 'filterOnly(title)', 'filterOnly(body)'],
        ]);
    }

    public function testWhereFiltersResultsByExactMatch(): void
    {
        $models = SearchableModel::withoutSyncingToSearch(fn () => new EloquentCollection([
            SearchableModel::create(['title' => 'PHP Guide', 'body' => 'Learn PHP']),
            SearchableModel::create(['title' => 'JavaScript Guide', 'body' => 'Learn JS']),
            SearchableModel::create(['title' => 'PHP Advanced', 'body' => 'Advanced PHP']),
        ]));
        $first = $models->first();

        $this->engine->update($models);
        $this->pollSearch($first->searchableAs(), '', 3);

        $results = SearchableModel::search('')
            ->where('id', $first->id)
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('PHP Guide', $results->first()->title);
    }

    public function testWhereInFiltersResultsByMultipleValues(): void
    {
        $models = SearchableModel::withoutSyncingToSearch(fn () => [
            SearchableModel::create(['title' => 'First', 'body' => 'Body']),
            SearchableModel::create(['title' => 'Second', 'body' => 'Body']),
            SearchableModel::create(['title' => 'Third', 'body' => 'Body']),
        ]);
        [$first, $second, $third] = $models;

        $this->engine->update(new EloquentCollection($models));
        $this->pollSearch($first->searchableAs(), '', 3);

        $results = SearchableModel::search('')
            ->whereIn('id', [$first->id, $third->id])
            ->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $first->id));
        $this->assertTrue($results->contains('id', $third->id));
        $this->assertFalse($results->contains('id', $second->id));
    }

    public function testWhereNotInExcludesSpecifiedValues(): void
    {
        $models = SearchableModel::withoutSyncingToSearch(fn () => [
            SearchableModel::create(['title' => 'First', 'body' => 'Body']),
            SearchableModel::create(['title' => 'Second', 'body' => 'Body']),
            SearchableModel::create(['title' => 'Third', 'body' => 'Body']),
        ]);
        [$first, $second, $third] = $models;

        $this->engine->update(new EloquentCollection($models));
        $this->pollSearch($first->searchableAs(), '', 3);

        $results = SearchableModel::search('')
            ->whereNotIn('id', [$second->id])
            ->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $first->id));
        $this->assertTrue($results->contains('id', $third->id));
        $this->assertFalse($results->contains('id', $second->id));
    }

    /**
     * Poll an Algolia index until the search returns the expected hit count.
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
