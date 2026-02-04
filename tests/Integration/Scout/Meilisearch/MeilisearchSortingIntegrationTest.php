<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Meilisearch;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Tests\Scout\Models\SearchableModel;

/**
 * Integration tests for Meilisearch sorting operations.
 *
 * @internal
 * @coversNothing
 */
class MeilisearchSortingIntegrationTest extends MeilisearchScoutIntegrationTestCase
{
    protected function setUpInCoroutine(): void
    {
        parent::setUpInCoroutine();

        $this->configureSortableIndex();
    }

    protected function configureSortableIndex(): void
    {
        $indexName = $this->prefixedIndexName('searchable_models');

        $task = $this->meilisearch->createIndex($indexName, ['primaryKey' => 'id']);
        $this->meilisearch->waitForTask($task['taskUid']);

        $index = $this->meilisearch->index($indexName);
        $task = $index->updateSettings([
            'sortableAttributes' => ['id', 'title'],
        ]);
        $this->meilisearch->waitForTask($task['taskUid']);
    }

    public function testOrderByAscendingSortsResultsCorrectly(): void
    {
        SearchableModel::create(['title' => 'Charlie', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Alpha', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Bravo', 'body' => 'Body']);

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $results = SearchableModel::search('')
            ->orderBy('title', 'asc')
            ->get();

        $this->assertCount(3, $results);
        $this->assertSame('Alpha', $results[0]->title);
        $this->assertSame('Bravo', $results[1]->title);
        $this->assertSame('Charlie', $results[2]->title);
    }

    public function testOrderByDescendingSortsResultsCorrectly(): void
    {
        SearchableModel::create(['title' => 'Alpha', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Bravo', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Charlie', 'body' => 'Body']);

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $results = SearchableModel::search('')
            ->orderBy('title', 'desc')
            ->get();

        $this->assertCount(3, $results);
        $this->assertSame('Charlie', $results[0]->title);
        $this->assertSame('Bravo', $results[1]->title);
        $this->assertSame('Alpha', $results[2]->title);
    }

    public function testOrderByDescHelperMethod(): void
    {
        SearchableModel::create(['title' => 'Alpha', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Bravo', 'body' => 'Body']);

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $results = SearchableModel::search('')
            ->orderByDesc('title')
            ->get();

        $this->assertCount(2, $results);
        $this->assertSame('Bravo', $results[0]->title);
        $this->assertSame('Alpha', $results[1]->title);
    }

    public function testOrderByNumericField(): void
    {
        $model1 = SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        $model2 = SearchableModel::create(['title' => 'Second', 'body' => 'Body']);
        $model3 = SearchableModel::create(['title' => 'Third', 'body' => 'Body']);

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $results = SearchableModel::search('')
            ->orderBy('id', 'desc')
            ->get();

        $this->assertCount(3, $results);
        $this->assertSame($model3->id, $results[0]->id);
        $this->assertSame($model2->id, $results[1]->id);
        $this->assertSame($model1->id, $results[2]->id);
    }
}
