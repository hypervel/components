<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Feature;

use Hypervel\Scout\Engines\CollectionEngine;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

class CollectionEngineTest extends ScoutTestCase
{
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
        $model2 = SearchableModel::create(['title' => 'Test B', 'body' => 'Body']);

        $results = SearchableModel::search('')
            ->where('id', $model1->id)
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals($model1->id, $results->first()->id);
    }

    public function testSearchWithMultipleComparisonsOnTheSameField(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        $second = SearchableModel::create(['title' => 'Second', 'body' => 'Body']);
        $third = SearchableModel::create(['title' => 'Third', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Fourth', 'body' => 'Body']);

        $results = SearchableModel::search('')
            ->where('id', '>=', $second->id)
            ->where('id', '<=', $third->id)
            ->get();

        $this->assertSame([$third->id, $second->id], $results->pluck('id')->all());
    }

    public function testSearchWithWhereInClause(): void
    {
        $model1 = SearchableModel::create(['title' => 'Test A', 'body' => 'Body']);
        $model2 = SearchableModel::create(['title' => 'Test B', 'body' => 'Body']);
        $model3 = SearchableModel::create(['title' => 'Test C', 'body' => 'Body']);

        $results = SearchableModel::search('')
            ->whereIn('id', [$model1->id, $model2->id])
            ->get();

        $this->assertCount(2, $results);
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

        $results = SearchableModel::search('uppercase')->get();
        $this->assertCount(1, $results);

        $results = SearchableModel::search('LOWERCASE')->get();
        $this->assertCount(1, $results);
    }

    public function testSearchTreatsStringZeroAsAQuery(): void
    {
        SearchableModel::create(['title' => 'Contains 0', 'body' => 'Body']);
        SearchableModel::create(['title' => 'No match', 'body' => 'Body']);

        $results = SearchableModel::search('0')->get();

        $this->assertCount(1, $results);
        $this->assertSame('Contains 0', $results->first()->title);
    }

    public function testUpdateAndDeleteAreNoOps(): void
    {
        $model = SearchableModel::create(['title' => 'Test', 'body' => 'Body']);
        $engine = new CollectionEngine;

        // These should not throw exceptions
        $engine->update($model->newCollection([$model]));
        $engine->delete($model->newCollection([$model]));
        $engine->flush($model);

        $this->assertTrue(true);
    }

    public function testCreateAndDeleteIndexAreNoOps(): void
    {
        $engine = new CollectionEngine;

        $this->assertNull($engine->createIndex('test'));
        $this->assertNull($engine->deleteIndex('test'));
    }

    public function testGetTotalCountReturnsCorrectCount(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        $builder = SearchableModel::search('');
        $engine = new CollectionEngine;
        $results = $engine->search($builder);

        $this->assertEquals(2, $engine->getTotalCount($results));
    }
}
