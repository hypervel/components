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
     * Configure attributesForFaceting so Algolia accepts filters on id/title/body.
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

    public function testComparisonFiltersAndEscapedStringValuesReachAlgolia(): void
    {
        $models = SearchableModel::withoutSyncingToSearch(fn () => new EloquentCollection([
            SearchableModel::create(['id' => 101, 'title' => 'A "quoted" \ guide', 'body' => 'Body']),
            SearchableModel::create(['id' => 102, 'title' => 'Other', 'body' => 'Body']),
            SearchableModel::create(['id' => 103, 'title' => 'Third', 'body' => 'Body']),
        ]));

        $this->engine->update($models);
        $this->pollSearch($models->first()->searchableAs(), '', 3);

        $results = SearchableModel::search('')
            ->where('id', '>', 101)
            ->where('id', '!=', 103)
            ->get();

        $this->assertSame([102], $results->pluck('id')->all());

        $results = SearchableModel::search('')
            ->where('title', 'A "quoted" \ guide')
            ->get();

        $this->assertSame([101], $results->pluck('id')->all());
    }

    public function testBackedEnumsRetainTheirNativeFilterValues(): void
    {
        $models = SearchableModel::withoutSyncingToSearch(fn () => new EloquentCollection([
            SearchableModel::create(['id' => 201, 'title' => 'Enum target', 'body' => 'Body']),
            SearchableModel::create(['id' => 202, 'title' => 'Other', 'body' => 'Body']),
        ]));

        $this->engine->update($models);
        $this->pollSearch($models->first()->searchableAs(), '', 2);

        $this->assertSame(
            [201],
            SearchableModel::search('')->where('id', AlgoliaFilterId::Target)->get()->pluck('id')->all()
        );
        $this->assertSame(
            [201],
            SearchableModel::search('')->where('title', AlgoliaFilterTitle::Target)->get()->pluck('id')->all()
        );
    }

    public function testApplicationFiltersComposeWithBuilderFiltersForSearchAndDeletion(): void
    {
        $models = SearchableModel::withoutSyncingToSearch(fn () => new EloquentCollection([
            SearchableModel::create(['id' => 701, 'title' => 'Target', 'body' => 'Body']),
            SearchableModel::create(['id' => 702, 'title' => 'Other', 'body' => 'Body']),
            SearchableModel::create(['id' => 703, 'title' => 'Excluded', 'body' => 'Body']),
        ]));
        $index = $models->first()->searchableAs();

        $this->engine->update($models);
        $this->pollSearch($index, '', 3);

        $results = SearchableModel::search('')
            ->options(['filters' => "title:'Target' OR title:'Other'"])
            ->where('id', 701)
            ->get();

        $this->assertSame([701], $results->pluck('id')->all());

        $this->engine->deleteByFilter(
            SearchableModel::search('')
                ->options(['filters' => "title:'Target' OR title:'Other'"])
                ->where('id', 701)
        );

        $this->assertCount(2, $this->pollSearch($index, '', 2));
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

enum AlgoliaFilterId: int
{
    case Target = 201;
}

enum AlgoliaFilterTitle: string
{
    case Target = 'Enum target';
}
