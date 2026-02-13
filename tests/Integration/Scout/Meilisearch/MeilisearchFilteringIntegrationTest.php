<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Meilisearch;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Tests\Scout\Models\SearchableModel;

/**
 * Integration tests for Meilisearch filtering operations.
 *
 * @internal
 * @coversNothing
 */
class MeilisearchFilteringIntegrationTest extends MeilisearchScoutIntegrationTestCase
{
    protected function setUpInCoroutine(): void
    {
        parent::setUpInCoroutine();

        // Configure filterable attributes for the test index
        $this->configureFilterableIndex();
    }

    protected function configureFilterableIndex(): void
    {
        $indexName = $this->prefixedIndexName('searchable_models');

        // Create index and configure filterable attributes
        $task = $this->meilisearch->createIndex($indexName, ['primaryKey' => 'id']);
        $this->meilisearch->waitForTask($task['taskUid']);

        $index = $this->meilisearch->index($indexName);
        $task = $index->updateSettings([
            'filterableAttributes' => ['id', 'title', 'body'],
            'sortableAttributes' => ['id', 'title'],
        ]);
        $this->meilisearch->waitForTask($task['taskUid']);
    }

    public function testWhereFiltersResultsByExactMatch(): void
    {
        SearchableModel::create(['title' => 'PHP Guide', 'body' => 'Learn PHP']);
        SearchableModel::create(['title' => 'JavaScript Guide', 'body' => 'Learn JS']);
        SearchableModel::create(['title' => 'PHP Advanced', 'body' => 'Advanced PHP']);

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $results = SearchableModel::search('')
            ->where('title', 'PHP Guide')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('PHP Guide', $results->first()->title);
    }

    public function testWhereWithNumericValue(): void
    {
        $model1 = SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        $model2 = SearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $results = SearchableModel::search('')
            ->where('id', $model1->id)
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame($model1->id, $results->first()->id);
    }

    public function testWhereInFiltersResultsByMultipleValues(): void
    {
        $model1 = SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        $model2 = SearchableModel::create(['title' => 'Second', 'body' => 'Body']);
        $model3 = SearchableModel::create(['title' => 'Third', 'body' => 'Body']);

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $results = SearchableModel::search('')
            ->whereIn('id', [$model1->id, $model3->id])
            ->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $model1->id));
        $this->assertTrue($results->contains('id', $model3->id));
        $this->assertFalse($results->contains('id', $model2->id));
    }

    public function testWhereNotInExcludesSpecifiedValues(): void
    {
        $model1 = SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        $model2 = SearchableModel::create(['title' => 'Second', 'body' => 'Body']);
        $model3 = SearchableModel::create(['title' => 'Third', 'body' => 'Body']);

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $results = SearchableModel::search('')
            ->whereNotIn('id', [$model1->id, $model3->id])
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame($model2->id, $results->first()->id);
    }

    public function testMultipleWhereClausesAreCombinedWithAnd(): void
    {
        SearchableModel::create(['title' => 'PHP Guide', 'body' => 'Content A']);
        SearchableModel::create(['title' => 'PHP Guide', 'body' => 'Content B']);
        SearchableModel::create(['title' => 'JS Guide', 'body' => 'Content A']);

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $results = SearchableModel::search('')
            ->where('title', 'PHP Guide')
            ->where('body', 'Content A')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('PHP Guide', $results->first()->title);
        $this->assertSame('Content A', $results->first()->body);
    }

    public function testCombinedWhereAndWhereIn(): void
    {
        $model1 = SearchableModel::create(['title' => 'PHP', 'body' => 'A']);
        $model2 = SearchableModel::create(['title' => 'PHP', 'body' => 'B']);
        $model3 = SearchableModel::create(['title' => 'JS', 'body' => 'A']);
        $model4 = SearchableModel::create(['title' => 'PHP', 'body' => 'C']);

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $results = SearchableModel::search('')
            ->where('title', 'PHP')
            ->whereIn('body', ['A', 'B'])
            ->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $model1->id));
        $this->assertTrue($results->contains('id', $model2->id));
    }
}
