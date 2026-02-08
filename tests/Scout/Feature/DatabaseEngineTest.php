<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Feature;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Scout\Engines\DatabaseEngine;
use Hypervel\Tests\Scout\Models\PrefixSearchableModel;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEngineTest extends ScoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set driver to database for these tests
        $this->app->get(Repository::class)->set('scout.driver', 'database');
    }

    public function testSearchReturnsMatchingModels(): void
    {
        SearchableModel::create(['title' => 'Hello World', 'body' => 'This is a test']);
        SearchableModel::create(['title' => 'Foo Bar', 'body' => 'Another test']);
        SearchableModel::create(['title' => 'Baz Qux', 'body' => 'No match here']);

        $results = SearchableModel::search('Hello')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Hello World', $results->first()->title);
    }

    public function testSearchReturnsAllModelsWithEmptyQuery(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body 1']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Body 2']);
        SearchableModel::create(['title' => 'Third', 'body' => 'Body 3']);

        $results = SearchableModel::search('')->get();

        $this->assertCount(3, $results);
    }

    public function testSearchWithWhereClause(): void
    {
        $model1 = SearchableModel::create(['title' => 'Test A', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Test B', 'body' => 'Body']);

        $results = SearchableModel::search('Test')
            ->where('id', $model1->id)
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals($model1->id, $results->first()->id);
    }

    public function testSearchWithWhereInClause(): void
    {
        $model1 = SearchableModel::create(['title' => 'Test A', 'body' => 'Body']);
        $model2 = SearchableModel::create(['title' => 'Test B', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Test C', 'body' => 'Body']);

        $results = SearchableModel::search('Test')
            ->whereIn('id', [$model1->id, $model2->id])
            ->get();

        $this->assertCount(2, $results);
    }

    public function testSearchWithWhereNotInClause(): void
    {
        $model1 = SearchableModel::create(['title' => 'Test A', 'body' => 'Body']);
        $model2 = SearchableModel::create(['title' => 'Test B', 'body' => 'Body']);
        $model3 = SearchableModel::create(['title' => 'Test C', 'body' => 'Body']);

        $results = SearchableModel::search('Test')
            ->whereNotIn('id', [$model1->id])
            ->get();

        $this->assertCount(2, $results);
        $this->assertFalse($results->contains('id', $model1->id));
    }

    public function testSearchWithLimit(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Third', 'body' => 'Body']);

        $results = SearchableModel::search('')->take(2)->get();

        $this->assertCount(2, $results);
    }

    public function testSearchWithPagination(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            SearchableModel::create(['title' => "Item {$i}", 'body' => 'Body']);
        }

        $page1 = SearchableModel::search('')->paginate(3, 'page', 1);
        $page2 = SearchableModel::search('')->paginate(3, 'page', 2);

        $this->assertCount(3, $page1->items());
        $this->assertCount(3, $page2->items());
        $this->assertEquals(10, $page1->total());
    }

    public function testSearchWithOrderBy(): void
    {
        SearchableModel::create(['title' => 'B Item', 'body' => 'Body']);
        SearchableModel::create(['title' => 'A Item', 'body' => 'Body']);
        SearchableModel::create(['title' => 'C Item', 'body' => 'Body']);

        $results = SearchableModel::search('')
            ->orderBy('title', 'asc')
            ->get();

        $this->assertEquals('A Item', $results->first()->title);
        $this->assertEquals('C Item', $results->last()->title);
    }

    public function testSearchMatchesInBody(): void
    {
        SearchableModel::create(['title' => 'No match', 'body' => 'The quick brown fox']);
        SearchableModel::create(['title' => 'Also no match', 'body' => 'Lazy dog']);

        $results = SearchableModel::search('fox')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('No match', $results->first()->title);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        SearchableModel::create(['title' => 'UPPERCASE', 'body' => 'Body']);
        SearchableModel::create(['title' => 'lowercase', 'body' => 'Body']);

        // SQLite uses LIKE which is case-insensitive by default
        $results = SearchableModel::search('uppercase')->get();
        $this->assertCount(1, $results);

        $results = SearchableModel::search('LOWERCASE')->get();
        $this->assertCount(1, $results);
    }

    public function testSearchByPrimaryKey(): void
    {
        $model1 = SearchableModel::create(['title' => 'Test A', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Test B', 'body' => 'Body']);

        // Search by the ID as a string
        $results = SearchableModel::search((string) $model1->id)->get();

        $this->assertCount(1, $results);
        $this->assertEquals($model1->id, $results->first()->id);
    }

    public function testUpdateAndDeleteAreNoOps(): void
    {
        $model = SearchableModel::create(['title' => 'Test', 'body' => 'Body']);
        $engine = new DatabaseEngine();

        // These should not throw exceptions since database is the index
        $engine->update($model->newCollection([$model]));
        $engine->delete($model->newCollection([$model]));
        $engine->flush($model);

        $this->assertTrue(true);
    }

    public function testCreateAndDeleteIndexAreNoOps(): void
    {
        $engine = new DatabaseEngine();

        $this->assertNull($engine->createIndex('test'));
        $this->assertNull($engine->deleteIndex('test'));
    }

    public function testGetTotalCountReturnsCorrectCount(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        $builder = SearchableModel::search('');
        $engine = new DatabaseEngine();
        $results = $engine->search($builder);

        $this->assertEquals(2, $engine->getTotalCount($results));
    }

    public function testMapIdsReturnsCorrectIds(): void
    {
        $model1 = SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        $model2 = SearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        $builder = SearchableModel::search('');
        $engine = new DatabaseEngine();
        $results = $engine->search($builder);

        $ids = $engine->mapIds($results);

        $this->assertContains($model1->id, $ids->all());
        $this->assertContains($model2->id, $ids->all());
    }

    public function testMapReturnsResults(): void
    {
        $model = SearchableModel::create(['title' => 'Test', 'body' => 'Body']);

        $builder = SearchableModel::search('Test');
        $engine = new DatabaseEngine();
        $results = $engine->search($builder);

        $mapped = $engine->map($builder, $results, $model);

        $this->assertCount(1, $mapped);
        $this->assertEquals('Test', $mapped->first()->title);
    }

    public function testLazyMapReturnsLazyCollection(): void
    {
        SearchableModel::create(['title' => 'Test', 'body' => 'Body']);

        $builder = SearchableModel::search('Test');
        $engine = new DatabaseEngine();
        $results = $engine->search($builder);
        $model = new SearchableModel();

        $lazyMapped = $engine->lazyMap($builder, $results, $model);

        $this->assertInstanceOf(\Hypervel\Support\LazyCollection::class, $lazyMapped);
        $this->assertCount(1, $lazyMapped);
    }

    public function testQueryCallbackIsApplied(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Third', 'body' => 'Body']);

        $results = SearchableModel::search('')
            ->query(function ($query) {
                return $query->where('title', 'First');
            })
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('First', $results->first()->title);
    }

    public function testSimplePagination(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            SearchableModel::create(['title' => "Item {$i}", 'body' => 'Body']);
        }

        $page = SearchableModel::search('')->simplePaginate(3, 'page', 1);

        $this->assertCount(3, $page->items());
        $this->assertTrue($page->hasMorePages());
    }

    public function testSearchUsingPrefixMatchesStartOfColumn(): void
    {
        // PrefixSearchableModel has #[SearchUsingPrefix(['title'])]
        // This means title searches use 'query%' pattern instead of '%query%'
        PrefixSearchableModel::create(['title' => 'Testing Prefix', 'body' => 'Body content']);
        PrefixSearchableModel::create(['title' => 'Another Testing', 'body' => 'Body content']);
        PrefixSearchableModel::create(['title' => 'Prefix Start', 'body' => 'Body content']);

        // "Test" should match "Testing Prefix" (starts with Test)
        // but NOT "Another Testing" (Testing is in the middle)
        $results = PrefixSearchableModel::search('Test')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Testing Prefix', $results->first()->title);
    }

    public function testSearchUsingPrefixDoesNotMatchMiddleOfColumn(): void
    {
        PrefixSearchableModel::create(['title' => 'Hello World', 'body' => 'Body']);
        PrefixSearchableModel::create(['title' => 'World Hello', 'body' => 'Body']);

        // "World" should only match "World Hello" (starts with World)
        // NOT "Hello World" (World is in the middle)
        $results = PrefixSearchableModel::search('World')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('World Hello', $results->first()->title);
    }

    public function testSearchUsingPrefixStillMatchesBodyWithFullWildcard(): void
    {
        // Body column is NOT in SearchUsingPrefix, so it uses %query%
        PrefixSearchableModel::create(['title' => 'No Match', 'body' => 'Contains keyword here']);
        PrefixSearchableModel::create(['title' => 'Also No Match', 'body' => 'keyword at start']);

        // "keyword" should match both because body uses full wildcard
        $results = PrefixSearchableModel::search('keyword')->get();

        $this->assertCount(2, $results);
    }

    public function testRegularModelUsesFullWildcardOnTitle(): void
    {
        // SearchableModel does NOT have SearchUsingPrefix
        // So title should use %query% pattern
        SearchableModel::create(['title' => 'Testing Prefix', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Another Testing', 'body' => 'Body']);

        // "Test" should match both because regular model uses %query%
        $results = SearchableModel::search('Test')->get();

        $this->assertCount(2, $results);
    }
}
