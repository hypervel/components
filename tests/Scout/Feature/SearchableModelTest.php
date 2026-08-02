<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Feature;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\Models\SoftDeletableSearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;
use LogicException;
use RuntimeException;

class SearchableModelTest extends ScoutTestCase
{
    public function testSearchableBootDefersModelInstanceWorkUntilAfterPublication(): void
    {
        $model = new SearchableModel;

        $this->assertSame('searchable_models', $model->getTable());
        $this->assertTrue(BaseCollection::hasMacro('searchable'));

        $dispatcher = SearchableModel::getEventDispatcher();

        $this->assertNotNull($dispatcher);
        $this->assertTrue($dispatcher->hasListeners(
            'eloquent.saved: ' . SearchableModel::class
        ));
    }

    public function testSearchReturnsBuilder(): void
    {
        $builder = SearchableModel::search('test');

        $this->assertInstanceOf(\Hypervel\Scout\Builder::class, $builder);
        $this->assertSame('test', $builder->query);
    }

    public function testSearchUsesModelSelectedBuilder(): void
    {
        $this->assertInstanceOf(CustomScoutBuilder::class, CustomScoutBuilderModel::search('test'));
    }

    public function testSearchHonorsBuilderContainerSubstitution(): void
    {
        $this->app->bind(Builder::class, ContainerScoutBuilder::class);

        $this->assertInstanceOf(ContainerScoutBuilder::class, SearchableModel::search('test'));
    }

    public function testSearchableAsReturnsTableName(): void
    {
        $model = new SearchableModel;

        $this->assertSame('searchable_models', $model->searchableAs());
    }

    public function testSearchableAsReturnsTableNameWithPrefix(): void
    {
        // Set a prefix in the config
        $this->app->make('config')
            ->set('scout.prefix', 'test_');

        $model = new SearchableModel;

        $this->assertSame('test_searchable_models', $model->searchableAs());
    }

    public function testToSearchableArrayReturnsModelArray(): void
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

    public function testGetScoutKeyReturnsModelKey(): void
    {
        $model = SearchableModel::create([
            'title' => 'Test Title',
            'body' => 'Test Body',
        ]);

        $this->assertSame($model->id, $model->getScoutKey());
    }

    public function testExistingModelMissingItsDefaultScoutKeyCannotBeIndexed(): void
    {
        Model::preventAccessingMissingAttributes(false);

        $model = SearchableModel::create([
            'title' => 'Test Title',
            'body' => 'Test Body',
        ]);
        $partialModel = SearchableModel::query()
            ->select('title')
            ->whereKey($model->getKey())
            ->firstOrFail();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Model [Hypervel\Tests\Scout\Models\SearchableModel] has no Scout key.');

        $partialModel->getScoutKey();
    }

    public function testUnsavedModelRetainsNullableScoutKeyIntrospection(): void
    {
        $this->assertNull((new SearchableModel)->getScoutKey());
    }

    public function testGetScoutKeyNameReturnsModelKeyName(): void
    {
        $model = new SearchableModel;

        $this->assertSame('id', $model->getScoutKeyName());
    }

    public function testShouldBeSearchableReturnsTrueByDefault(): void
    {
        $model = new SearchableModel;

        $this->assertTrue($model->shouldBeSearchable());
    }

    public function testDisableSearchSyncingPreventsIndexing(): void
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

    public function testWithoutSyncingToSearchExecutesCallbackAndRestoresState(): void
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

    public function testWithoutSyncingToSearchRestoresStateOnException(): void
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

    public function testWithoutSyncingToSearchPreservesOuterDisabledScope(): void
    {
        SearchableModel::withoutSyncingToSearch(function (): void {
            $this->assertFalse(SearchableModel::isSearchSyncingEnabled());

            SearchableModel::withoutSyncingToSearch(function (): void {
                $this->assertFalse(SearchableModel::isSearchSyncingEnabled());
            });

            $this->assertFalse(SearchableModel::isSearchSyncingEnabled());
        });

        $this->assertTrue(SearchableModel::isSearchSyncingEnabled());
    }

    public function testWithoutSyncingToSearchRestoresPreexistingDisabledState(): void
    {
        SearchableModel::disableSearchSyncing();

        try {
            SearchableModel::withoutSyncingToSearch(function (): void {
                $this->assertFalse(SearchableModel::isSearchSyncingEnabled());
            });

            $this->assertFalse(SearchableModel::isSearchSyncingEnabled());
        } finally {
            SearchableModel::enableSearchSyncing();
        }
    }

    public function testMakeAllSearchableQueryReturnsBuilder(): void
    {
        $query = SearchableModel::makeAllSearchableQuery();

        $this->assertInstanceOf(\Hypervel\Database\Eloquent\Builder::class, $query);
    }

    public function testScoutMetadataCanBeSetAndRetrieved(): void
    {
        $model = new SearchableModel;

        $model->withScoutMetadata('_rankingScore', 0.95);
        $model->withScoutMetadata('_highlight', ['title' => '<em>test</em>']);

        $metadata = $model->scoutMetadata();

        $this->assertSame(0.95, $metadata['_rankingScore']);
        $this->assertSame(['title' => '<em>test</em>'], $metadata['_highlight']);
    }

    public function testModelCanBeSearched(): void
    {
        // Create some models
        SearchableModel::create(['title' => 'First Post', 'body' => 'Content']);
        SearchableModel::create(['title' => 'Second Post', 'body' => 'More content']);
        SearchableModel::create(['title' => 'Third Item', 'body' => 'Other content']);

        // Search should work with collection engine
        $results = SearchableModel::search('Post')->get();

        $this->assertCount(2, $results);
    }

    public function testSoftDeletedModelsAreExcludedByDefault(): void
    {
        // Set soft delete config
        $this->app->make('config')
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

    public function testSoftDeletedModelsCanBeIncludedWithWithTrashed(): void
    {
        // Set soft delete config
        $this->app->make('config')
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

    public function testSearchIndexShouldBeUpdatedReturnsTrueByDefault(): void
    {
        $model = new SearchableModel;

        $this->assertTrue($model->searchIndexShouldBeUpdated());
    }

    public function testWasSearchableBeforeUpdateReturnsTrueByDefault(): void
    {
        $model = new SearchableModel;

        $this->assertTrue($model->wasSearchableBeforeUpdate());
    }

    public function testWasSearchableBeforeDeleteReturnsTrueByDefault(): void
    {
        $model = new SearchableModel;

        $this->assertTrue($model->wasSearchableBeforeDelete());
    }

    public function testIndexableAsReturnsSearchableAsByDefault(): void
    {
        $model = new SearchableModel;

        $this->assertSame($model->searchableAs(), $model->indexableAs());
    }

    public function testGetScoutKeyTypeReturnsModelKeyType(): void
    {
        $model = new SearchableModel;

        $this->assertSame('int', $model->getScoutKeyType());
    }

    public function testMakeSearchableUsingReturnsModelsUnchangedByDefault(): void
    {
        $model = new SearchableModel;
        $collection = $model->newCollection([new SearchableModel, new SearchableModel]);

        $result = $model->makeSearchableUsing($collection);

        $this->assertSame($collection, $result);
    }
}

class CustomScoutBuilderModel extends SearchableModel
{
    protected static string $scoutBuilder = CustomScoutBuilder::class;
}

class CustomScoutBuilder extends Builder
{
}

class ContainerScoutBuilder extends Builder
{
}
