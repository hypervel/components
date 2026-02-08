<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Meilisearch;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Tests\Scout\Models\SoftDeleteSearchableModel;

/**
 * Integration tests for Scout soft delete behavior with Meilisearch.
 *
 * @internal
 * @coversNothing
 */
class MeilisearchSoftDeleteIntegrationTest extends MeilisearchScoutIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Enable soft delete support in Scout
        $this->app->get(Repository::class)->set('scout.soft_delete', true);
    }

    protected function setUpInCoroutine(): void
    {
        parent::setUpInCoroutine();

        $this->configureSoftDeleteIndex();
    }

    protected function configureSoftDeleteIndex(): void
    {
        $indexName = $this->prefixedIndexName('soft_deletable_searchable_models');

        $task = $this->meilisearch->createIndex($indexName, ['primaryKey' => 'id']);
        $this->meilisearch->waitForTask($task['taskUid']);

        $index = $this->meilisearch->index($indexName);
        $task = $index->updateSettings([
            'filterableAttributes' => ['__soft_deleted'],
        ]);
        $this->meilisearch->waitForTask($task['taskUid']);
    }

    public function testDefaultSearchExcludesSoftDeletedModels(): void
    {
        $model1 = SoftDeleteSearchableModel::create(['title' => 'Active One', 'body' => 'Content']);
        $model2 = SoftDeleteSearchableModel::create(['title' => 'Active Two', 'body' => 'Content']);
        $model3 = SoftDeleteSearchableModel::create(['title' => 'Deleted One', 'body' => 'Content']);

        // Index all models
        SoftDeleteSearchableModel::withTrashed()->get()->each(
            fn ($m) => $this->engine->update(new EloquentCollection([$m]))
        );
        $this->waitForMeilisearchTasks();

        // Soft delete one model and re-index it
        $model3->delete();
        $this->engine->update(new EloquentCollection([$model3->fresh()]));
        $this->waitForMeilisearchTasks();

        // Default search should exclude soft-deleted model
        $results = SoftDeleteSearchableModel::search('')->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $model1->id));
        $this->assertTrue($results->contains('id', $model2->id));
        $this->assertFalse($results->contains('id', $model3->id));
    }

    public function testWithTrashedIncludesSoftDeletedModels(): void
    {
        $model1 = SoftDeleteSearchableModel::create(['title' => 'Active', 'body' => 'Content']);
        $model2 = SoftDeleteSearchableModel::create(['title' => 'Deleted', 'body' => 'Content']);

        // Index all models
        SoftDeleteSearchableModel::withTrashed()->get()->each(
            fn ($m) => $this->engine->update(new EloquentCollection([$m]))
        );
        $this->waitForMeilisearchTasks();

        // Soft delete one model and re-index it
        $model2->delete();
        $this->engine->update(new EloquentCollection([$model2->fresh()]));
        $this->waitForMeilisearchTasks();

        // withTrashed should include soft-deleted model
        $results = SoftDeleteSearchableModel::search('')->withTrashed()->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $model1->id));
        $this->assertTrue($results->contains('id', $model2->id));
    }

    public function testOnlyTrashedReturnsOnlySoftDeletedModels(): void
    {
        $model1 = SoftDeleteSearchableModel::create(['title' => 'Active', 'body' => 'Content']);
        $model2 = SoftDeleteSearchableModel::create(['title' => 'Deleted One', 'body' => 'Content']);
        $model3 = SoftDeleteSearchableModel::create(['title' => 'Deleted Two', 'body' => 'Content']);

        // Index all models
        SoftDeleteSearchableModel::withTrashed()->get()->each(
            fn ($m) => $this->engine->update(new EloquentCollection([$m]))
        );
        $this->waitForMeilisearchTasks();

        // Soft delete two models and re-index them
        $model2->delete();
        $model3->delete();
        $this->engine->update(new EloquentCollection([$model2->fresh()]));
        $this->engine->update(new EloquentCollection([$model3->fresh()]));
        $this->waitForMeilisearchTasks();

        // onlyTrashed should return only soft-deleted models
        $results = SoftDeleteSearchableModel::search('')->onlyTrashed()->get();

        $this->assertCount(2, $results);
        $this->assertFalse($results->contains('id', $model1->id));
        $this->assertTrue($results->contains('id', $model2->id));
        $this->assertTrue($results->contains('id', $model3->id));
    }
}
