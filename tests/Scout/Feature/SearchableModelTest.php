<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Feature;

use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\Models\SoftDeletableSearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class SearchableModelTest extends ScoutTestCase
{
    public function testSearchReturnsBuilder()
    {
        $builder = SearchableModel::search('test');

        $this->assertInstanceOf(\Hypervel\Scout\Builder::class, $builder);
        $this->assertSame('test', $builder->query);
    }

    public function testSearchableAsReturnsTableName()
    {
        $model = new SearchableModel();

        $this->assertSame('searchable_models', $model->searchableAs());
    }

    public function testSearchableAsReturnsTableNameWithPrefix()
    {
        // Set a prefix in the config
        $this->app->get(\Hypervel\Contracts\Config\Repository::class)
            ->set('scout.prefix', 'test_');

        $model = new SearchableModel();

        $this->assertSame('test_searchable_models', $model->searchableAs());
    }

    public function testToSearchableArrayReturnsModelArray()
    {
        $model = SearchableModel::create([
            'title' => 'Test Title',
            'body' => 'Test Body',
        ]);

        $searchable = $model->toSearchableArray();

        $this->assertArrayHasKey('id', $searchable);
        $this->assertArrayHasKey('title', $searchable);
        $this->assertArrayHasKey('body', $searchable);
        $this->assertSame('Test Title', $searchable['title']);
    }

    public function testGetScoutKeyReturnsModelKey()
    {
        $model = SearchableModel::create([
            'title' => 'Test Title',
            'body' => 'Test Body',
        ]);

        $this->assertSame($model->id, $model->getScoutKey());
    }

    public function testGetScoutKeyNameReturnsModelKeyName()
    {
        $model = new SearchableModel();

        $this->assertSame('id', $model->getScoutKeyName());
    }

    public function testShouldBeSearchableReturnsTrueByDefault()
    {
        $model = new SearchableModel();

        $this->assertTrue($model->shouldBeSearchable());
    }

    public function testDisableSearchSyncingPreventsIndexing()
    {
        // Initially syncing is enabled
        $this->assertTrue(SearchableModel::isSearchSyncingEnabled());

        // Disable syncing
        SearchableModel::disableSearchSyncing();

        $this->assertFalse(SearchableModel::isSearchSyncingEnabled());

        // Re-enable syncing
        SearchableModel::enableSearchSyncing();

        $this->assertTrue(SearchableModel::isSearchSyncingEnabled());
    }

    public function testWithoutSyncingToSearchExecutesCallbackAndRestoresState()
    {
        $this->assertTrue(SearchableModel::isSearchSyncingEnabled());

        $result = SearchableModel::withoutSyncingToSearch(function () {
            // Syncing should be disabled inside callback
            $this->assertFalse(SearchableModel::isSearchSyncingEnabled());
            return 'callback result';
        });

        // Syncing should be restored after callback
        $this->assertTrue(SearchableModel::isSearchSyncingEnabled());
        $this->assertSame('callback result', $result);
    }

    public function testWithoutSyncingToSearchRestoresStateOnException()
    {
        $this->assertTrue(SearchableModel::isSearchSyncingEnabled());

        try {
            SearchableModel::withoutSyncingToSearch(function () {
                throw new RuntimeException('Test exception');
            });
        } catch (RuntimeException) {
            // Expected
        }

        // Syncing should be restored even after exception
        $this->assertTrue(SearchableModel::isSearchSyncingEnabled());
    }

    public function testMakeAllSearchableQueryReturnsBuilder()
    {
        $query = SearchableModel::makeAllSearchableQuery();

        $this->assertInstanceOf(\Hypervel\Database\Eloquent\Builder::class, $query);
    }

    public function testScoutMetadataCanBeSetAndRetrieved()
    {
        $model = new SearchableModel();

        $model->withScoutMetadata('_rankingScore', 0.95);
        $model->withScoutMetadata('_highlight', ['title' => '<em>test</em>']);

        $metadata = $model->scoutMetadata();

        $this->assertSame(0.95, $metadata['_rankingScore']);
        $this->assertSame(['title' => '<em>test</em>'], $metadata['_highlight']);
    }

    public function testModelCanBeSearched()
    {
        // Create some models
        SearchableModel::create(['title' => 'First Post', 'body' => 'Content']);
        SearchableModel::create(['title' => 'Second Post', 'body' => 'More content']);
        SearchableModel::create(['title' => 'Third Item', 'body' => 'Other content']);

        // Search should work with collection engine
        $results = SearchableModel::search('Post')->get();

        $this->assertCount(2, $results);
    }

    public function testSoftDeletedModelsAreExcludedByDefault()
    {
        // Set soft delete config
        $this->app->get(\Hypervel\Contracts\Config\Repository::class)
            ->set('scout.soft_delete', true);

        $model = SoftDeletableSearchableModel::create([
            'title' => 'Test Title',
            'body' => 'Test Body',
        ]);

        // Delete the model
        $model->delete();

        // Search should not find the deleted model
        $results = SoftDeletableSearchableModel::search('Test')->get();

        $this->assertCount(0, $results);
    }

    public function testSoftDeletedModelsCanBeIncludedWithWithTrashed()
    {
        // Set soft delete config
        $this->app->get(\Hypervel\Contracts\Config\Repository::class)
            ->set('scout.soft_delete', true);

        $model = SoftDeletableSearchableModel::create([
            'title' => 'Test Title',
            'body' => 'Test Body',
        ]);

        // Delete the model
        $model->delete();

        // Search with trashed should find the deleted model
        $results = SoftDeletableSearchableModel::search('Test')
            ->withTrashed()
            ->get();

        // Should find the model (note: CollectionEngine may not fully support this)
        $this->assertCount(1, $results);
    }

    public function testSearchIndexShouldBeUpdatedReturnsTrueByDefault()
    {
        $model = new SearchableModel();

        $this->assertTrue($model->searchIndexShouldBeUpdated());
    }

    public function testWasSearchableBeforeUpdateReturnsTrueByDefault()
    {
        $model = new SearchableModel();

        $this->assertTrue($model->wasSearchableBeforeUpdate());
    }

    public function testWasSearchableBeforeDeleteReturnsTrueByDefault()
    {
        $model = new SearchableModel();

        $this->assertTrue($model->wasSearchableBeforeDelete());
    }

    public function testIndexableAsReturnsSearchableAsByDefault()
    {
        $model = new SearchableModel();

        $this->assertSame($model->searchableAs(), $model->indexableAs());
    }

    public function testGetScoutKeyTypeReturnsModelKeyType()
    {
        $model = new SearchableModel();

        $this->assertSame('int', $model->getScoutKeyType());
    }

    public function testMakeSearchableUsingReturnsModelsUnchangedByDefault()
    {
        $model = new SearchableModel();
        $collection = $model->newCollection([new SearchableModel(), new SearchableModel()]);

        $result = $model->makeSearchableUsing($collection);

        $this->assertSame($collection, $result);
    }
}
