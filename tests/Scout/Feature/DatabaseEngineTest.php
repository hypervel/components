<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Feature;

use ArgumentCountError;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Attributes\SearchUsingFullText;
use Hypervel\Scout\Attributes\SearchUsingPrefix;
use Hypervel\Scout\Engines\DatabaseEngine;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Searchable;
use Hypervel\Support\ClassInvoker;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\Scout\Fixtures\Models\PrefixSearchableModel;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModel;
use Hypervel\Tests\Scout\Fixtures\Models\UuidSearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

class DatabaseEngineTest extends ScoutTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('scout.driver', 'database');
    }

    public function testItCanRetrieveResultsWithEmptySearch(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body 1']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Body 2']);
        SearchableModel::create(['title' => 'Third', 'body' => 'Body 3']);

        $this->assertCount(3, SearchableModel::search()->get());
        $this->assertCount(3, SearchableModel::search('')->get());
    }

    public function testSemanticSearchRequiresPostgresAndEmbeddingSource(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage((new SearchableModel)->getConnection()->getDriverName() === 'pgsql'
            ? 'Database semantic search requires the model to define a [toSearchableEmbedding] method.'
            : 'Database semantic search requires PostgreSQL with pgvector.');

        SearchableModel::search('Taylor')->semantic()->get();
    }

    public function testHybridSearchFallsBackToNormalTextSearchWhenVectorsAreNotSupported(): void
    {
        $this->createAbigailAndTaylor();

        $models = SearchableModel::search('Taylor')->hybrid()->get();

        $this->assertCount(1, $models);
        $this->assertSame('Taylor Otwell', $models->first()->title);
    }

    public function testItDoesNotAddSearchWhereClausesWithEmptySearch(): void
    {
        SearchableModel::search('')->query(function ($builder): void {
            $this->assertSame('select * from "searchable_models"', $builder->toSql());
        })->get();
    }

    public function testItAddsSearchWhereClausesWithNonEmptySearch(): void
    {
        SearchableModel::search('Taylor')->query(function ($builder): void {
            $this->assertSame(
                'select * from "searchable_models" where ("searchable_models"."title" like ? or "searchable_models"."body" like ?)',
                $builder->toSql()
            );
        })->get();
    }

    public function testItMatchesThePrimaryKeyExactlyForANumericSearch(): void
    {
        SearchableModel::search('1')->query(function ($builder): void {
            $this->assertSame(
                'select * from "searchable_models" where ("searchable_models"."id" = ? or "searchable_models"."title" like ? or "searchable_models"."body" like ?)',
                $builder->toSql()
            );
        })->get();
    }

    public function testItSearchesACustomStringScoutKeyWithLikeForANumericSearch(): void
    {
        DatabaseCustomScoutKeyModel::create(['title' => 'user123@hypervel.org', 'body' => 'Body']);

        $models = DatabaseCustomScoutKeyModel::search('123')->get();

        $this->assertCount(1, $models);
        $this->assertSame('user123@hypervel.org', $models->first()->title);
    }

    public function testItMatchesACustomIntegerScoutKeyExactlyForANumericSearch(): void
    {
        $model = new class extends Model {
            use Searchable;

            protected ?string $table = 'scout_through_models';

            protected array $guarded = [];

            /**
             * Get the key name used to index the model.
             */
            public function getScoutKeyName(): string
            {
                return 'intermediate_id';
            }

            /**
             * Get the key type used to query the model.
             */
            public function getScoutKeyType(): string
            {
                return 'int';
            }

            /**
             * Get the indexable data array for the model.
             */
            public function toSearchableArray(): array
            {
                return [
                    'id' => $this->id,
                    'intermediate_id' => $this->intermediate_id,
                    'title' => $this->title,
                ];
            }
        };

        $model::search('30')->query(function ($builder): void {
            $this->assertSame(
                'select * from "scout_through_models" where ("scout_through_models"."id" = ? or "scout_through_models"."intermediate_id" = ? or "scout_through_models"."title" like ?)',
                $builder->toSql()
            );
        })->get();
    }

    public function testItCanRetrieveResults(): void
    {
        $taylor = SearchableModel::create(['title' => 'Taylor Otwell', 'body' => 'taylor@hypervel.org']);
        $abigail = SearchableModel::create(['title' => 'Abigail Otwell', 'body' => 'abigail@hypervel.org']);

        $models = SearchableModel::search('Taylor')->where('body', 'taylor@hypervel.org')->get();
        $this->assertCount(1, $models);
        $this->assertSame($taylor->id, $models[0]->id);

        $models = SearchableModel::search('Taylor')->query(function ($query): void {
            $query->where('body', 'like', 'taylor@hypervel.org');
        })->get();
        $this->assertCount(1, $models);
        $this->assertSame($taylor->id, $models[0]->id);

        $models = SearchableModel::search('Abigail')->where('body', 'abigail@hypervel.org')->get();
        $this->assertCount(1, $models);
        $this->assertSame($abigail->id, $models[0]->id);

        $this->assertCount(0, SearchableModel::search('Taylor')->where('body', 'abigail@hypervel.org')->get());
        $this->assertCount(1, SearchableModel::search('Taylor')->where('id', $taylor->id)->get());
        $this->assertCount(2, SearchableModel::search('otwell')->get());
        $this->assertCount(2, SearchableModel::search('hypervel')->get());
        $this->assertCount(0, SearchableModel::search('foo')->get());
        $this->assertCount(0, SearchableModel::search('Abigail')->where('body', 'taylor@hypervel.org')->get());
    }

    public function testItCanPaginateResults(): void
    {
        SearchableModel::create(['title' => 'Taylor Otwell', 'body' => 'taylor@hypervel.org']);
        SearchableModel::create(['title' => 'Abigail Otwell', 'body' => 'abigail@hypervel.org']);

        $this->assertCount(1, SearchableModel::search('Taylor')->where('body', 'taylor@hypervel.org')->paginate());
        $this->assertCount(0, SearchableModel::search('Taylor')->where('body', 'abigail@hypervel.org')->paginate());
        $this->assertCount(2, SearchableModel::search('hypervel')->paginate());

        for ($i = 1; $i <= 8; ++$i) {
            SearchableModel::create(['title' => "Item {$i}", 'body' => 'Body']);
        }

        $page1 = SearchableModel::search('')->paginate(3, 'page', 1);
        $page2 = SearchableModel::search('')->paginate(3, 'page', 2);

        $this->assertCount(3, $page1->items());
        $this->assertCount(3, $page2->items());
        $this->assertSame(10, $page1->total());
    }

    public function testItCanPaginateUsingACustomPageName(): void
    {
        SearchableModel::create(['title' => 'Taylor Otwell', 'body' => 'taylor@hypervel.org']);

        $models = SearchableModel::search('Taylor')->where('body', 'taylor@hypervel.org')->paginate();
        $this->assertStringContainsString('page=1', $models->url(1));

        $models = SearchableModel::search('Taylor')->where('body', 'taylor@hypervel.org')->paginate(pageName: 'foo');
        $this->assertStringContainsString('foo=1', $models->url(1));

        $models = SearchableModel::search('Taylor')->where('body', 'taylor@hypervel.org')->paginate(pageName: 'bar');
        $this->assertStringContainsString('bar=1', $models->url(1));
    }

    public function testItCanSimplePaginateUsingACustomPageName(): void
    {
        SearchableModel::create(['title' => 'Taylor Otwell', 'body' => 'taylor@hypervel.org']);

        $models = SearchableModel::search('Taylor')->where('body', 'taylor@hypervel.org')->simplePaginate();
        $this->assertStringContainsString('page=1', $models->url(1));

        $models = SearchableModel::search('Taylor')->where('body', 'taylor@hypervel.org')->simplePaginate(pageName: 'foo');
        $this->assertStringContainsString('foo=1', $models->url(1));

        $models = SearchableModel::search('Taylor')->where('body', 'taylor@hypervel.org')->simplePaginate(pageName: 'bar');
        $this->assertStringContainsString('bar=1', $models->url(1));
    }

    public function testLimitIsApplied(): void
    {
        SearchableModel::create(['title' => 'Taylor Otwell', 'body' => 'taylor@hypervel.org']);
        SearchableModel::create(['title' => 'Abigail Otwell', 'body' => 'abigail@hypervel.org']);

        $this->assertCount(2, SearchableModel::search('hypervel')->get());
        $this->assertCount(1, SearchableModel::search('hypervel')->take(1)->get());
    }

    public function testTapIsApplied(): void
    {
        SearchableModel::create(['title' => 'Taylor Otwell', 'body' => 'taylor@hypervel.org']);
        SearchableModel::create(['title' => 'Abigail Otwell', 'body' => 'abigail@hypervel.org']);

        $this->assertCount(2, SearchableModel::search('hypervel')->get());

        $models = SearchableModel::search('hypervel')->tap(function ($query) {
            return $query->take(1);
        })->get();

        $this->assertCount(1, $models);
    }

    public function testItCanOrderResults(): void
    {
        SearchableModel::create(['title' => 'Taylor Otwell', 'body' => 'taylor@hypervel.org']);
        SearchableModel::create(['title' => 'Abigail Otwell', 'body' => 'abigail@hypervel.org']);

        $models = SearchableModel::search('hypervel')->orderBy('title', 'asc')->take(1)->get();
        $this->assertCount(1, $models);
        $this->assertSame('Abigail Otwell', $models[0]->title);

        $modelsPaginate = SearchableModel::search('hypervel')->orderBy('title', 'asc')->paginate(1, 'page', 1);
        $this->assertCount(1, $modelsPaginate);
        $this->assertSame('Abigail Otwell', $modelsPaginate[0]->title);

        $modelsSimplePaginate = SearchableModel::search('hypervel')->orderBy('title', 'asc')->simplePaginate(1, 'page', 1);
        $this->assertCount(1, $modelsSimplePaginate);
        $this->assertSame('Abigail Otwell', $modelsSimplePaginate[0]->title);

        $models = SearchableModel::search('hypervel')->orderBy('title', 'desc')->take(1)->get();
        $this->assertCount(1, $models);
        $this->assertSame('Taylor Otwell', $models[0]->title);

        $models = SearchableModel::search('hypervel')->orderByDesc('title')->take(1)->get();
        $this->assertCount(1, $models);
        $this->assertSame('Taylor Otwell', $models[0]->title);

        $modelsPaginate = SearchableModel::search('hypervel')->orderBy('title', 'desc')->paginate(1, 'page', 1);
        $this->assertCount(1, $modelsPaginate);
        $this->assertSame('Taylor Otwell', $modelsPaginate[0]->title);

        $modelsPaginate = SearchableModel::search('hypervel')->orderByDesc('title')->paginate(1, 'page', 1);
        $this->assertCount(1, $modelsPaginate);
        $this->assertSame('Taylor Otwell', $modelsPaginate[0]->title);

        $modelsSimplePaginate = SearchableModel::search('hypervel')->orderBy('title', 'desc')->simplePaginate(1, 'page', 1);
        $this->assertCount(1, $modelsSimplePaginate);
        $this->assertSame('Taylor Otwell', $modelsSimplePaginate[0]->title);

        $modelsSimplePaginate = SearchableModel::search('hypervel')->orderByDesc('title')->simplePaginate(1, 'page', 1);
        $this->assertCount(1, $modelsSimplePaginate);
        $this->assertSame('Taylor Otwell', $modelsSimplePaginate[0]->title);
    }

    public function testItUsesScoutQuery(): void
    {
        $related = SearchableModel::create([
            'title' => 'Related',
            'body' => 'This related row is searchable',
        ]);

        $model = new class extends Model {
            use Searchable;

            protected ?string $table = 'scout_through_models';

            protected array $guarded = [];

            /**
             * Build the base query for database Scout searches.
             */
            public function newScoutQuery(): EloquentBuilder
            {
                return $this->newQuery()
                    ->select('scout_through_models.*')
                    ->join('searchable_models', 'searchable_models.id', '=', 'scout_through_models.intermediate_id');
            }

            /**
             * Get the indexable data array for the model.
             */
            public function toSearchableArray(): array
            {
                return [
                    'title' => $this->title,
                    'searchable_models.body' => '',
                ];
            }
        };

        $model::create([
            'intermediate_id' => $related->id,
            'title' => 'hypervel',
        ]);

        $models = $model::search('related row')->get();

        $this->assertCount(1, $models);
        $this->assertSame('hypervel', $models[0]->title);
        $this->assertSame($related->id, $models[0]->intermediate_id);
    }

    public function testItCanFilterWithGreaterThan(): void
    {
        $this->createAbigailAndTaylor();

        $models = SearchableModel::search()->where('title', '>', 'B')->get();

        $this->assertSame(['Taylor Otwell'], $models->pluck('title')->all());
    }

    public function testItCanFilterWithLessThan(): void
    {
        $this->createAbigailAndTaylor();

        $models = SearchableModel::search()->where('title', '<', 'B')->get();

        $this->assertSame(['Abigail Otwell'], $models->pluck('title')->all());
    }

    public function testItCanFilterWithGreaterThanOrEqual(): void
    {
        $this->createAbigailAndTaylor();

        $this->assertSame(['Taylor Otwell'], SearchableModel::search()->where('title', '>=', 'T')->get()->pluck('title')->all());
        $this->assertCount(2, SearchableModel::search()->where('title', '>=', 'A')->get());
    }

    public function testItCanFilterWithLessThanOrEqual(): void
    {
        $this->createAbigailAndTaylor();

        $this->assertSame(['Abigail Otwell'], SearchableModel::search()->where('title', '<=', 'Abigail Otwell')->get()->pluck('title')->all());
        $this->assertCount(2, SearchableModel::search()->where('title', '<=', 'Taylor Otwell')->get());
    }

    public function testItCanFilterWithNotEqual(): void
    {
        $this->createAbigailAndTaylor();

        $this->assertSame(['Taylor Otwell'], SearchableModel::search()->where('title', '!=', 'Abigail Otwell')->get()->pluck('title')->all());
        $this->assertSame(['Abigail Otwell'], SearchableModel::search()->where('title', '!=', 'Taylor Otwell')->get()->pluck('title')->all());
    }

    public function testItCanFilterWithMultipleWhereComparisons(): void
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

    public function testIntegerPrimaryKeyUsesExactMatchingOnly(): void
    {
        SearchableModel::create(['id' => 1, 'title' => 'First', 'body' => 'Body']);
        SearchableModel::create(['id' => 10, 'title' => 'Tenth', 'body' => 'Body']);

        $results = SearchableModel::search('1')->get();

        $this->assertSame([1], $results->pluck('id')->all());
    }

    public function testStringPrimaryKeyRemainsPartiallySearchable(): void
    {
        UuidSearchableModel::create([
            'id' => '018f0000-0000-7000-8000-000000000001',
            'title' => 'First',
            'body' => 'Body',
        ]);
        UuidSearchableModel::create([
            'id' => '018f0000-0000-7000-8000-000000000002',
            'title' => 'Second',
            'body' => 'Body',
        ]);

        $results = UuidSearchableModel::search('000000000001')->get();

        $this->assertSame(
            ['018f0000-0000-7000-8000-000000000001'],
            $results->pluck('id')->all(),
        );
    }

    public function testCustomScoutKeyIsUsedForDefaultOrder(): void
    {
        DatabaseCustomScoutKeyModel::create(['id' => 1, 'title' => 'Zulu', 'body' => 'Body']);
        DatabaseCustomScoutKeyModel::create(['id' => 2, 'title' => 'Alpha', 'body' => 'Body']);
        DatabaseCustomScoutKeyModel::create(['id' => 10, 'title' => 'Bravo', 'body' => 'Body']);

        $this->assertSame(
            ['Zulu', 'Bravo', 'Alpha'],
            DatabaseCustomScoutKeyModel::search('')->get()->pluck('title')->all(),
        );
    }

    public function testUpdateAndDeleteAreNoOps(): void
    {
        $model = SearchableModel::create(['title' => 'Test', 'body' => 'Body']);
        $engine = new DatabaseEngine;

        // These should not throw exceptions since database is the index
        $engine->update($model->newCollection([$model]));
        $engine->delete($model->newCollection([$model]));
        $engine->flush($model);

        $this->assertTrue(SearchableModel::query()->whereKey($model->getKey())->exists());
    }

    public function testSearchAttributeMetadataIsCachedPerEngineInstance(): void
    {
        $engine = new DatabaseEngine;
        $invoker = new ClassInvoker($engine);

        $prefixBuilder = PrefixSearchableModel::search('hello');
        $plainBuilder = SearchableModel::search('hello');

        $this->assertSame(['title'], $invoker->getPrefixColumns($prefixBuilder));
        $this->assertSame(['title'], $invoker->getPrefixColumns($prefixBuilder));
        $this->assertSame([], $invoker->getFullTextColumns($plainBuilder));
        $this->assertSame([], $invoker->getFullTextOptions($plainBuilder));

        $this->assertSame(
            ['title'],
            $invoker->attributeColumns[PrefixSearchableModel::class][SearchUsingPrefix::class]
        );
        $this->assertSame(
            [],
            $invoker->attributeColumns[SearchableModel::class][SearchUsingFullText::class]
        );
        $this->assertSame([], $invoker->fullTextOptions[SearchableModel::class]);
    }

    public function testNamedSearchAttributeArgumentsUseTheAttributeContract(): void
    {
        $engine = new DatabaseEngine;
        $invoker = new ClassInvoker($engine);
        $builder = NamedSearchAttributesModel::search('hello');

        $this->assertSame(['title'], $invoker->getPrefixColumns($builder));
        $this->assertSame(['body'], $invoker->getFullTextColumns($builder));
        $this->assertSame(
            ['mode' => 'websearch', 'language' => 'english'],
            $invoker->getFullTextOptions($builder)
        );

        $this->assertSame(['title'], $invoker->getPrefixColumns($builder));
        $this->assertSame(['body'], $invoker->getFullTextColumns($builder));
    }

    public function testIntegerPrimaryKeyIsExcludedFromCachedTextSearchAttributes(): void
    {
        $invoker = new ClassInvoker(new DatabaseEngine);
        $builder = IntegerKeySearchAttributesModel::search('1');

        $this->assertSame(['title'], $invoker->getPrefixColumns($builder));
        $this->assertSame(['body'], $invoker->getFullTextColumns($builder));
        $this->assertSame(
            ['title'],
            $invoker->attributeColumns[IntegerKeySearchAttributesModel::class][SearchUsingPrefix::class],
        );
        $this->assertSame(
            ['body'],
            $invoker->attributeColumns[IntegerKeySearchAttributesModel::class][SearchUsingFullText::class],
        );
    }

    public function testMalformedSearchAttributeFailsFast(): void
    {
        $this->expectException(ArgumentCountError::class);

        (new ClassInvoker(new DatabaseEngine))->getPrefixColumns(
            MalformedSearchAttributeModel::search('hello')
        );
    }

    public function testCreateAndDeleteIndexAreNoOps(): void
    {
        $engine = new DatabaseEngine;

        $this->assertNull($engine->createIndex('test'));
        $this->assertNull($engine->deleteIndex('test'));
    }

    public function testGetTotalCountReturnsCorrectCount(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        $builder = SearchableModel::search('');
        $engine = new DatabaseEngine;
        $results = $engine->search($builder);

        $this->assertEquals(2, $engine->getTotalCount($results));
    }

    public function testMapIdsReturnsCorrectIds(): void
    {
        $model1 = SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        $model2 = SearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        $builder = SearchableModel::search('');
        $engine = new DatabaseEngine;
        $results = $engine->search($builder);

        $ids = $engine->mapIds($results);

        $this->assertContains($model1->id, $ids->all());
        $this->assertContains($model2->id, $ids->all());
    }

    public function testMapReturnsResults(): void
    {
        $model = SearchableModel::create(['title' => 'Test', 'body' => 'Body']);

        $builder = SearchableModel::search('Test');
        $engine = new DatabaseEngine;
        $results = $engine->search($builder);

        $mapped = $engine->map($builder, $results, $model);

        $this->assertCount(1, $mapped);
        $this->assertEquals('Test', $mapped->first()->title);
    }

    public function testLazyMapReturnsLazyCollection(): void
    {
        SearchableModel::create(['title' => 'Test', 'body' => 'Body']);

        $builder = SearchableModel::search('Test');
        $engine = new DatabaseEngine;
        $results = $engine->search($builder);
        $model = new SearchableModel;

        $lazyMapped = $engine->lazyMap($builder, $results, $model);

        $this->assertInstanceOf(LazyCollection::class, $lazyMapped);
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

    /**
     * Create the models used by the where comparison tests.
     */
    private function createAbigailAndTaylor(): void
    {
        SearchableModel::create(['title' => 'Taylor Otwell', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Abigail Otwell', 'body' => 'Body']);
    }
}

class NamedSearchAttributesModel extends SearchableModel
{
    #[SearchUsingPrefix(columns: ['title'])]
    #[SearchUsingFullText(columns: ['body'], options: ['mode' => 'websearch', 'language' => 'english'])]
    public function toSearchableArray(): array
    {
        return parent::toSearchableArray();
    }
}

class MalformedSearchAttributeModel extends SearchableModel
{
    #[SearchUsingPrefix]
    public function toSearchableArray(): array
    {
        return parent::toSearchableArray();
    }
}

class DatabaseCustomScoutKeyModel extends SearchableModel
{
    /**
     * Get the value used to index the model.
     */
    public function getScoutKey(): mixed
    {
        return $this->title;
    }

    /**
     * Get the key name used to index the model.
     */
    public function getScoutKeyName(): string
    {
        return 'title';
    }

    /**
     * Get the key type used to query the model.
     */
    public function getScoutKeyType(): string
    {
        return 'string';
    }
}

class IntegerKeySearchAttributesModel extends SearchableModel
{
    #[SearchUsingPrefix(columns: ['id', 'title'])]
    #[SearchUsingFullText(columns: ['id', 'body'])]
    public function toSearchableArray(): array
    {
        return parent::toSearchableArray();
    }
}
