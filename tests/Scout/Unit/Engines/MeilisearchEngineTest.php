<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Engines;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Hypervel\Ai\AiServiceProvider;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\DeletesByFilter;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Hypervel\Scout\EngineOperation;
use Hypervel\Scout\EngineOperationRunner;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Scout\Scout;
use Hypervel\Scout\Searchable;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModelWithNativeEmbedding;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModelWithPrecomputedEmbedding;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Meilisearch\Client;
use Meilisearch\Contracts\IndexesResults;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\TimeOutException;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

#[WithConfig('ai.caching.embeddings.store', 'array')]
class MeilisearchEngineTest extends TestCase
{
    use VerifiesDoubles;

    /**
     * Register the embedding provider used by semantic searches.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [AiServiceProvider::class];
    }

    public function testUpdateAddsDocumentsToIndex(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $client->expects('index')
            ->with('test_index')
            ->returns($index);

        $index->expects('addDocuments')
            ->with([
                ['id' => 1, 'title' => 'Test'],
            ], 'id');

        $engine = new MeilisearchEngine($client);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('indexableAs')->returns('test_index');
        $model->allows('toSearchableArray')->returns(['id' => 1, 'title' => 'Test']);
        $model->allows('scoutMetadata')->returns([]);
        $model->allows('getScoutKeyName')->returns('id');
        $model->allows('getScoutKey')->returns(1);

        $engine->update(new EloquentCollection([$model]));
    }

    public function testUpdateEmptyCollectionDoesNothing(): void
    {
        $client = Double::for(Client::class)->strict();
        $client->expects('index')->never();

        $engine = new MeilisearchEngine($client);
        $engine->update(new EloquentCollection);
    }

    public function testUpdateWithSoftDeletesAddsSoftDeleteMetadata(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $client->expects('index')
            ->with('test_index')
            ->returns($index);

        $index->expects('addDocuments')
            ->with(Argument::satisfies(function ($documents) {
                return isset($documents[0]['__soft_deleted']);
            }), 'id');

        $engine = new MeilisearchEngine($client, softDelete: true);

        $model = Double::for(MeilisearchTestSoftDeleteModel::class)->strict();
        $model->allows('indexableAs')->returns('test_index');
        $model->allows('toSearchableArray')->returns(['id' => 1, 'title' => 'Test']);
        $model->allows('scoutMetadata')->returns(['__soft_deleted' => 0]);
        $model->allows('getScoutKeyName')->returns('id');
        $model->allows('getScoutKey')->returns(1);
        $model->expects('pushSoftDeleteMetadata');

        $engine->update(new EloquentCollection([$model]));
    }

    public function testUpdatePreparesTheFinalSearchableDocument(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();
        $client->expects('index')->with('test_index')->returns($index);
        $index->expects('addDocuments')
            ->with([[
                'title' => 'Test',
                '__soft_deleted' => 0,
                'id' => 1,
                'tenant_id' => 42,
            ]], 'id');

        $engine = new MeilisearchEngine($client);
        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('indexableAs')->returns('test_index');
        $model->allows('toSearchableArray')->returns(['title' => 'Test']);
        $model->allows('scoutMetadata')->returns(['__soft_deleted' => 0]);
        $model->allows('getScoutKeyName')->returns('id');
        $model->allows('getScoutKey')->returns(1);

        Scout::prepareSearchableDocumentUsing(function (
            array $document,
            Model $givenModel,
            Engine $givenEngine
        ) use ($model, $engine): array {
            $this->assertSame([
                'title' => 'Test',
                '__soft_deleted' => 0,
                'id' => 1,
            ], $document);
            $this->assertSame($model, $givenModel);
            $this->assertSame($engine, $givenEngine);

            return [...$document, 'tenant_id' => 42];
        });

        $engine->update(new EloquentCollection([$model]));
    }

    public function testUpdateAddsPrecomputedEmbeddingsToVectorsBeforePreparingDocuments(): void
    {
        Embeddings::fake()->preventStrayEmbeddings();

        $client = Double::for(Client::class)->strict();
        $engine = $this->engineWithEmbedding($client, ['embedder' => 'default', 'dimensions' => 2]);

        $first = new SearchableModelWithPrecomputedEmbedding(['id' => 10, 'name' => 'First']);
        $first->setAttribute('embedding', [0.1, 0.2]);
        $first->setAttribute('_vectors', ['other' => [0.9, 0.8]]);

        $second = new SearchableModelWithPrecomputedEmbedding(['id' => 20, 'name' => 'Second']);
        $second->setAttribute('embedding', [0.3, 0.4]);

        $preparedVectors = [];

        Scout::prepareSearchableDocumentUsing(function (array $document) use (&$preparedVectors): array {
            $preparedVectors[] = $document['_vectors'];

            return $document;
        });

        $client->expects('index')->with('table')->returns($index = Double::for(Indexes::class)->strict());
        $index->expects('addDocuments')->with([
            [
                'id' => 10,
                'name' => 'First',
                'embedding' => [0.1, 0.2],
                '_vectors' => ['other' => [0.9, 0.8], 'default' => [0.1, 0.2]],
            ],
            [
                'id' => 20,
                'name' => 'Second',
                'embedding' => [0.3, 0.4],
                '_vectors' => ['default' => [0.3, 0.4]],
            ],
        ], 'id');

        $engine->update($first->newCollection([$first, $second]));

        $this->assertSame([
            ['other' => [0.9, 0.8], 'default' => [0.1, 0.2]],
            ['default' => [0.3, 0.4]],
        ], $preparedVectors);
    }

    public function testUpdateAddsPrecomputedAndGeneratedEmbeddingsToVectors(): void
    {
        Embeddings::fake([[[0.3, 0.4]]])->preventStrayEmbeddings();

        $client = Double::for(Client::class)->strict();
        $engine = $this->engineWithEmbedding($client, [
            'embedder' => 'default',
            'dimensions' => 2,
            'provider' => 'openai',
            'model' => 'text-embedding-test',
        ]);

        $precomputed = new SearchableModelWithPrecomputedEmbedding(['id' => 10, 'name' => 'Precomputed']);
        $precomputed->setAttribute('embedding', [0.1, 0.2]);
        $precomputed->setAttribute('_vectors', ['other' => [0.9, 0.8]]);
        $generated = new SearchableModelWithPrecomputedEmbedding(['id' => 20, 'name' => 'Generate this']);

        $client->expects('index')->with('table')->returns($index = Double::for(Indexes::class)->strict());
        $index->expects('addDocuments')->with(Argument::satisfies(fn (array $objects): bool => $objects[0]['_vectors'] === [
            'other' => [0.9, 0.8],
            'default' => [0.1, 0.2],
        ] && $objects[1]['_vectors'] === ['default' => [0.3, 0.4]]), 'id');

        $engine->update($precomputed->newCollection([$precomputed, $generated]));

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['Generate this']
            && $prompt->dimensions === 2
            && $prompt->provider->name() === 'openai'
            && $prompt->model === 'text-embedding-test');
    }

    public function testUpdateDoesNotAddVectorsWhenUsingNativeEmbeddings(): void
    {
        Embeddings::fake()->preventStrayEmbeddings();

        $client = Double::for(Client::class)->strict();
        $engine = $this->engineWithEmbedding(
            $client,
            ['embedder' => 'default', 'driver' => 'meilisearch'],
            SearchableModelWithNativeEmbedding::class,
        );

        $model = new SearchableModelWithNativeEmbedding(['id' => 1, 'name' => 'Model 1']);

        $client->expects('index')->with('table')->returns($index = Double::for(Indexes::class)->strict());
        $index->expects('addDocuments')->with([
            ['id' => 1, 'name' => 'Model 1'],
        ], 'id');

        $engine->update($model->newCollection([$model]));
    }

    public function testUpdatePreservesUserProvidedVectorsWhenUsingNativeEmbeddings(): void
    {
        Embeddings::fake()->preventStrayEmbeddings();

        $client = Double::for(Client::class)->strict();
        $engine = $this->engineWithEmbedding(
            $client,
            ['embedder' => 'default', 'driver' => 'meilisearch'],
            SearchableModelWithNativeEmbedding::class,
        );

        $model = new SearchableModelWithNativeEmbedding(['id' => 1, 'name' => 'Model 1']);
        $model->setAttribute('_vectors', [
            'default' => [
                'embeddings' => [0.1, 0.2],
                'regenerate' => false,
            ],
        ]);

        $client->expects('index')->with('table')->returns($index = Double::for(Indexes::class)->strict());
        $index->expects('addDocuments')->with([
            [
                'id' => 1,
                'name' => 'Model 1',
                '_vectors' => [
                    'default' => [
                        'embeddings' => [0.1, 0.2],
                        'regenerate' => false,
                    ],
                ],
            ],
        ], 'id');

        $engine->update($model->newCollection([$model]));
    }

    public function testDeleteRemovesDocumentsFromIndex(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $client->expects('index')
            ->with('test_index')
            ->returns($index);

        $index->expects('deleteDocuments')
            ->with([1, 2]);

        $engine = new MeilisearchEngine($client);

        $model1 = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model1->allows('indexableAs')->returns('test_index');
        $model1->allows('getScoutKey')->returns(1);

        $model2 = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model2->allows('getScoutKey')->returns(2);

        $engine->delete(new EloquentCollection([$model1, $model2]));
    }

    public function testDeleteWithRemoveableScoutCollectionUsesStoredScoutKey(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $client->expects('index')
            ->with('chirps')
            ->returns($index);

        $index->expects('deleteDocuments')
            ->with(['stored-scout-key']);

        $engine = new MeilisearchEngine($client);

        $model = new MeilisearchTestChirpModel;
        $model->setRawAttributes(['scout_id' => 'stored-scout-key']);

        $engine->delete(new RemoveableScoutCollection([$model]));
    }

    public function testDeleteEmptyCollectionDoesNothing(): void
    {
        $client = Double::for(Client::class)->strict();
        $client->expects('index')->never();

        $engine = new MeilisearchEngine($client);
        $engine->delete(new EloquentCollection);
    }

    public function testSearchPerformsSearchOnMeilisearch(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $client->expects('index')
            ->with('test_index')
            ->returns($index);

        $index->expects('rawSearch')
            ->with('test query', Argument::any())
            ->returns(['hits' => [], 'totalHits' => 0]);

        $engine = new MeilisearchEngine($client);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('searchableAs')->returns('test_index');
        $model->allows('getScoutKeyName')->returns('id');

        $builder = new Builder($model, 'test query');

        $result = $engine->search($builder);

        $this->assertEquals(['hits' => [], 'totalHits' => 0], $result);
    }

    public function testSearchWithFilters(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $client->expects('index')
            ->with('test_index')
            ->returns($index);

        $index->expects('rawSearch')
            ->with('query', Argument::satisfies(function ($params) {
                return $params['filter'] === 'status="active" AND score>=10 AND label!="draft\"review\\\copy" AND archived_at IS NOT NULL AND tags IN ["a\"b\\\c", true] AND hidden NOT IN ["draft", false]';
            }))
            ->returns(['hits' => [], 'totalHits' => 0]);

        $engine = new MeilisearchEngine($client);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('searchableAs')->returns('test_index');
        $model->allows('getScoutKeyName')->returns('id');

        $builder = new Builder($model, 'query');
        $builder->where('status', 'active')
            ->where('score', '>=', 10)
            ->where('label', '!=', 'draft"review\copy')
            ->where('archived_at', '!=', null)
            ->whereIn('tags', ['a"b\c', true])
            ->whereNotIn('hidden', ['draft', false]);

        $engine->search($builder);
    }

    public function testWhereValuesAreEscaped(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();
        $client->expects('index')->with('test_index')->returns($index);
        $index->expects('rawSearch')->with('', [
            'filter' => 'size="12\" pipe" AND path="C:\\\dir\\\"',
        ])->returns(['hits' => [], 'totalHits' => 0]);

        $engine = new MeilisearchEngine($client);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('searchableAs')->returns('test_index');
        $model->allows('getScoutKeyName')->returns('id');

        $builder = new Builder($model, '');
        $builder->where('size', '12" pipe');
        $builder->where('path', 'C:\dir\\');

        $engine->search($builder);
    }

    public function testWhereInAndWhereNotInValuesAreEscaped(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();
        $client->expects('index')->with('test_index')->returns($index);
        $index->expects('rawSearch')->with('', [
            'filter' => 'category IN ["12\" pipe", "back\\\slash"] AND tag NOT IN ["a\"] OR team_id = 2 OR tag IN [\"b"]',
        ])->returns(['hits' => [], 'totalHits' => 0]);

        $engine = new MeilisearchEngine($client);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('searchableAs')->returns('test_index');
        $model->allows('getScoutKeyName')->returns('id');

        $builder = new Builder($model, '');
        $builder->whereIn('category', ['12" pipe', 'back\slash']);
        $builder->whereNotIn('tag', ['a"] OR team_id = 2 OR tag IN ["b']);

        $engine->search($builder);
    }

    #[DataProvider('filterCombinations')]
    public function testSearchComposesApplicationAndBuilderFilters(
        string|array|null $applicationFilter,
        bool $hasBuilderFilter,
        string|array|null $expectedFilter
    ): void {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();
        $client->expects('index')->with('test_index')->returns($index);
        $expected = $expectedFilter === null ? [] : ['filter' => $expectedFilter];
        $index->expects('rawSearch')->with('query', $expected);

        $engine = new MeilisearchEngine($client);
        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('searchableAs')->returns('test_index');
        $model->allows('getScoutKeyName')->returns('id');
        $builder = new Builder($model, 'query');

        if ($applicationFilter !== null) {
            $builder->options(['filter' => $applicationFilter]);
        }

        if ($hasBuilderFilter) {
            $builder->where('tenant_id', 42);
        }

        $engine->search($builder);
    }

    /**
     * Provide application and Builder filter combinations.
     *
     * @return array<string, array{null|array<mixed>|string, bool, null|array<mixed>|string}>
     */
    public static function filterCombinations(): array
    {
        return [
            'neither side' => [null, false, null],
            'application string only' => ['status="active" OR status="trial"', false, 'status="active" OR status="trial"'],
            'whitespace application and builder' => ['   ', true, 'tenant_id=42'],
            'builder only' => [null, true, 'tenant_id=42'],
            'both string sides' => [
                'status="active" OR status="trial"',
                true,
                '(status="active" OR status="trial") AND (tenant_id=42)',
            ],
            'application array only' => [
                [['status="active"', 'status="trial"'], 'visible=true'],
                false,
                [['status="active"', 'status="trial"'], 'visible=true'],
            ],
            'application array and builder' => [
                [['status="active"', 'status="trial"'], 'visible=true'],
                true,
                [['status="active"', 'status="trial"'], 'visible=true', 'tenant_id=42'],
            ],
        ];
    }

    public function testPaginateComposesApplicationAndBuilderFilters(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();
        $client->expects('index')->with('test_index')->returns($index);
        $index->expects('rawSearch')->with('query', [
            'filter' => '(status="active" OR status="trial") AND (tenant_id=42)',
            'hitsPerPage' => 15,
            'page' => 2,
        ]);

        $engine = new MeilisearchEngine($client);
        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('searchableAs')->returns('test_index');
        $model->allows('getScoutKeyName')->returns('id');
        $builder = (new Builder($model, 'query'))
            ->options(['filter' => 'status="active" OR status="trial"'])
            ->where('tenant_id', 42);

        $engine->paginate($builder, 15, 2);
    }

    public function testSearchCallbackReceivesFinalComposedOptions(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();
        $client->expects('index')->with('test_index')->returns($index);
        $index->expects('rawSearch')->never();
        $engine = new MeilisearchEngine($client);
        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('searchableAs')->returns('test_index');
        $model->allows('getScoutKeyName')->returns('id');
        $builder = new Builder($model, 'query', function ($givenIndex, $query, $options) use ($index) {
            $this->assertSame($index, $givenIndex);
            $this->assertSame('query', $query);
            $this->assertSame([
                'filter' => [['status="active"', 'status="trial"'], 'tenant_id=42'],
            ], $options);

            return ['hits' => [], 'totalHits' => 0];
        });
        $builder->options(['filter' => [['status="active"', 'status="trial"']]])->where('tenant_id', 42);

        $engine->search($builder);
    }

    public function testSearchWithBackedEnumFilters(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $client->expects('index')
            ->with('test_index')
            ->returns($index);

        $index->expects('rawSearch')
            ->with('query', Argument::satisfies(function ($params) {
                return $params['filter'] === 'status="published" AND priority=1 AND statuses IN ["draft", "published"] AND priorities NOT IN [1, 2]';
            }))
            ->returns(['hits' => [], 'totalHits' => 0]);

        $engine = new MeilisearchEngine($client);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('searchableAs')->returns('test_index');
        $model->allows('getScoutKeyName')->returns('id');

        $builder = new Builder($model, 'query');
        $builder->where('status', MeilisearchStringStatus::Published)
            ->where('priority', MeilisearchIntegerPriority::High)
            ->whereIn('statuses', [MeilisearchStringStatus::Draft, MeilisearchStringStatus::Published])
            ->whereNotIn('priorities', [MeilisearchIntegerPriority::High, MeilisearchIntegerPriority::Low]);

        $engine->search($builder);
    }

    public function testPaginatePerformsPaginatedSearch(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $client->expects('index')
            ->with('test_index')
            ->returns($index);

        $index->expects('rawSearch')
            ->with('query', Argument::satisfies(function ($params) {
                return $params['hitsPerPage'] === 15 && $params['page'] === 2;
            }))
            ->returns(['hits' => [], 'totalHits' => 0]);

        $engine = new MeilisearchEngine($client);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('searchableAs')->returns('test_index');
        $model->allows('getScoutKeyName')->returns('id');

        $builder = new Builder($model, 'query');

        $engine->paginate($builder, 15, 2);
    }

    public function testSemanticSearchWithNativeEmbeddingsOmitsTheQueryVector(): void
    {
        Embeddings::fake()->preventStrayEmbeddings();

        $client = Double::for(Client::class)->strict();
        $engine = $this->engineWithEmbedding(
            $client,
            ['embedder' => 'default', 'driver' => 'meilisearch'],
            SearchableModelWithNativeEmbedding::class,
        );

        $client->expects('index')->with('table')->returns($index = Double::for(Indexes::class)->strict());
        $index->expects('rawSearch')->with('conceptual query', Argument::satisfies(fn (array $parameters): bool => $parameters === [
            'hitsPerPage' => 10,
            'hybrid' => [
                'embedder' => 'default',
                'semanticRatio' => 1.0,
            ],
            'rankingScoreThreshold' => 0,
            'filter' => 'status="published"',
        ]))->returns(['hits' => []]);

        $builder = (new Builder(new SearchableModelWithNativeEmbedding, 'conceptual query'))
            ->semantic(minSimilarity: 0)
            ->where('status', 'published')
            ->take(10);

        $engine->search($builder);
    }

    public function testHybridSearchWithNativeEmbeddingsAcceptsAPrecomputedQueryVector(): void
    {
        Embeddings::fake()->preventStrayEmbeddings();

        $client = Double::for(Client::class)->strict();
        $engine = $this->engineWithEmbedding(
            $client,
            ['embedder' => 'default', 'driver' => 'meilisearch'],
            SearchableModelWithNativeEmbedding::class,
        );

        $client->expects('index')->with('table')->returns($index = Double::for(Indexes::class)->strict());
        $index->expects('rawSearch')->with('combined query', [
            'vector' => [0.4, 0.6],
            'hitsPerPage' => 5,
            'page' => 2,
            'hybrid' => [
                'embedder' => 'default',
                'semanticRatio' => 2 / 3,
            ],
        ])->returns(['hits' => []]);

        $builder = (new Builder(new SearchableModelWithNativeEmbedding, 'combined query'))
            ->options(['vector' => [0.4, 0.6]])
            ->hybrid(textWeight: 1, semanticWeight: 2);

        $engine->paginate($builder, 5, 2);
    }

    public function testSemanticSearchRejectsAnUnsupportedEmbeddingDriver(): void
    {
        $client = Double::for(Client::class)->strict();
        $client->expects('index')->never();
        $engine = $this->engineWithEmbedding($client, ['embedder' => 'default', 'dimensions' => 2, 'driver' => 'foo']);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))->semantic();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIs('The [foo] Meilisearch embedding driver is not supported.');

        $engine->search($builder);
    }

    public function testHybridSearchAcceptsAPrecomputedQueryVectorAndNormalizesWeights(): void
    {
        Embeddings::fake()->preventStrayEmbeddings();

        $client = Double::for(Client::class)->strict();
        $engine = $this->engineWithEmbedding($client, ['embedder' => 'default', 'dimensions' => 2]);

        $client->expects('index')->with('table')->returns($index = Double::for(Indexes::class)->strict());
        $index->expects('rawSearch')->with('combined query', [
            'vector' => [0.4, 0.6],
            'hitsPerPage' => 5,
            'page' => 2,
            'hybrid' => [
                'embedder' => 'default',
                'semanticRatio' => 2 / 3,
            ],
            'rankingScoreThreshold' => 0.5,
        ])->returns(['hits' => []]);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'combined query'))
            ->options(['vector' => [0.4, 0.6]])
            ->hybrid(textWeight: 1, semanticWeight: 2, minSimilarity: 0.5);

        $engine->paginate($builder, 5, 2);
    }

    public function testSemanticSearchGeneratesAQueryVector(): void
    {
        Embeddings::fake([[[0.25, 0.75]]])->preventStrayEmbeddings();

        $client = Double::for(Client::class)->strict();
        $engine = $this->engineWithEmbedding($client, ['embedder' => 'default', 'dimensions' => 2]);

        $client->expects('index')->times(2)->with('table')->returns($index = Double::for(Indexes::class)->strict());
        $index->expects('rawSearch')->times(2)->with('conceptual query', [
            'hitsPerPage' => 10,
            'vector' => [0.25, 0.75],
            'hybrid' => ['embedder' => 'default', 'semanticRatio' => 1.0],
            'rankingScoreThreshold' => 0,
            'filter' => 'status="published"',
        ])->returns(['hits' => []]);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))
            ->semantic(minSimilarity: 0)
            ->where('status', 'published')
            ->take(10);

        $engine->search($builder);

        $engine->search($builder);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['conceptual query']);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidSemanticSearchOptions')]
    public function testSemanticSearchRejectsInvalidOptions(array $options, string $message): void
    {
        $client = Double::for(Client::class)->strict();
        $client->expects('index')->never();
        $engine = $this->engineWithEmbedding($client, ['embedder' => 'default', 'dimensions' => 2]);

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIs($message);

        $engine->search(
            (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))
                ->options($options)
                ->semantic()
        );
    }

    /**
     * Provide semantic search options Meilisearch rejects.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidSemanticSearchOptions(): array
    {
        return [
            'custom hybrid option' => [
                ['vector' => [0.1, 0.2], 'hybrid' => ['embedder' => 'other']],
                'Meilisearch semantic and hybrid searches cannot be combined with a custom [hybrid] option.',
            ],
            'empty query vector' => [
                ['vector' => []],
                'The Meilisearch query [vector] must be a non-empty embedding array.',
            ],
        ];
    }

    public function testMapIdsReturnsEmptyCollectionIfNoHits(): void
    {
        $client = Double::for(Client::class)->strict();
        $engine = new MeilisearchEngine($client);

        $results = $engine->mapIdsFrom([
            'totalHits' => 0,
            'hits' => [],
        ], 'id');

        $this->assertCount(0, $results);
    }

    public function testMapIdsReturnsCorrectPrimaryKeys(): void
    {
        $client = Double::for(Client::class)->strict();
        $engine = new MeilisearchEngine($client);

        $results = $engine->mapIdsFrom([
            'totalHits' => 4,
            'hits' => [
                ['id' => 1, 'title' => 'Test 1'],
                ['id' => 2, 'title' => 'Test 2'],
                ['id' => 3, 'title' => 'Test 3'],
                ['id' => 4, 'title' => 'Test 4'],
            ],
        ], 'id');

        $this->assertEquals([1, 2, 3, 4], $results->all());
    }

    public function testMapCorrectlyMapsResultsToModels(): void
    {
        $client = Double::for(Client::class)->strict();
        $engine = new MeilisearchEngine($client);

        // Create a mock searchable model that tracks scout metadata
        $searchableModel = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $searchableModel->allows('getScoutKey')->returns(1);
        $searchableModel->expects('withScoutMetadata')
            ->with('_rankingScore', 0.86);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('getScoutKeyName')->returns('id');
        $model->allows('getScoutModelsByIds')->returns(new EloquentCollection([$searchableModel]));
        $model->allows('newCollection')
            ->resolves(fn ($models) => new EloquentCollection($models));

        $builder = Double::for(Builder::class)->strict();

        $results = $engine->map($builder, [
            'totalHits' => 1,
            'hits' => [
                ['id' => 1, '_rankingScore' => 0.86],
            ],
        ], $model);

        $this->assertCount(1, $results);
    }

    public function testMapReturnsEmptyCollectionWhenNoHits(): void
    {
        $client = Double::for(Client::class)->strict();
        $engine = new MeilisearchEngine($client);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('newCollection')->returns(new EloquentCollection);

        $builder = Double::for(Builder::class)->strict();

        $results = $engine->map($builder, ['hits' => []], $model);

        $this->assertCount(0, $results);
    }

    public function testMapRespectsOrder(): void
    {
        $client = Double::for(Client::class)->strict();
        $engine = new MeilisearchEngine($client);

        // Create mock models
        $mockModels = [];
        foreach ([1, 2, 3, 4] as $id) {
            $mock = Double::for(MeilisearchTestSearchableModel::class)->strict();
            $mock->allows('getScoutKey')->returns($id);
            $mockModels[] = $mock;
        }

        $models = new EloquentCollection($mockModels);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('getScoutKeyName')->returns('id');
        $model->allows('getScoutModelsByIds')->returns($models);
        $model->allows('newCollection')
            ->resolves(fn ($models) => new EloquentCollection($models));

        $builder = Double::for(Builder::class)->strict();

        $results = $engine->map($builder, [
            'totalHits' => 4,
            'hits' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 4],
                ['id' => 3],
            ],
        ], $model);

        $this->assertCount(4, $results);
        // Check order is respected: 1, 2, 4, 3
        $resultIds = $results->map(fn ($m) => $m->getScoutKey())->all();
        $this->assertEquals([1, 2, 4, 3], $resultIds);
    }

    public function testLazyMapReturnsEmptyCollectionWhenNoHits(): void
    {
        $client = Double::for(Client::class)->strict();
        $engine = new MeilisearchEngine($client);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('newCollection')->returns(new EloquentCollection);

        $builder = Double::for(Builder::class)->strict();

        $results = $engine->lazyMap($builder, ['hits' => []], $model);

        $this->assertInstanceOf(LazyCollection::class, $results);
        $this->assertCount(0, $results);
    }

    public function testGetTotalCountReturnsTotalHits(): void
    {
        $client = Double::for(Client::class)->strict();
        $engine = new MeilisearchEngine($client);

        $this->assertSame(3, $engine->getTotalCount(['totalHits' => 3]));
    }

    public function testGetTotalCountReturnsEstimatedTotalHits(): void
    {
        $client = Double::for(Client::class)->strict();
        $engine = new MeilisearchEngine($client);

        $this->assertSame(5, $engine->getTotalCount(['estimatedTotalHits' => 5]));
    }

    public function testGetTotalCountReturnsZeroWhenNoCountAvailable(): void
    {
        $client = Double::for(Client::class)->strict();
        $engine = new MeilisearchEngine($client);

        $this->assertSame(0, $engine->getTotalCount([]));
    }

    public function testFlushDeletesAllDocuments(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $client->expects('index')
            ->with('test_index')
            ->returns($index);

        $index->expects('deleteAllDocuments');

        $engine = new MeilisearchEngine($client);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('indexableAs')->returns('test_index');

        $engine->flush($model);
    }

    public function testDeleteByFilterPreparesTheBuilderAndWaitsForTheDefaultWriteIndex(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();
        $client->expects('index')->with('users_write')->returns($index);
        $index->expects('deleteDocuments')
            ->with(['filter' => [['visibility=true', 'visibility=false'], 'status="active" AND tenant_id=42']])
            ->returns(['taskUid' => 123]);
        $client->expects('waitForTask')
            ->with(123, 500_000, 5_000)
            ->returns(['status' => 'succeeded']);

        $runner = new EngineOperationRunner;
        $observer = new MeilisearchEngineOperationObserver;
        $runner->observe($observer);
        $engine = (new MeilisearchEngine($client))->setOperationRunner($runner, 'meilisearch');
        $this->assertInstanceOf(DeletesByFilter::class, $engine);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->expects('indexableAs')->returns('users_write');
        $model->expects('searchableAs')->never();
        $builder = (new Builder($model, ''))
            ->options(['filter' => [['visibility=true', 'visibility=false']]])
            ->where('status', 'active');

        Scout::prepareBuilderUsing(function (Builder $givenBuilder, Engine $givenEngine) use ($builder, $engine): void {
            $this->assertSame($builder, $givenBuilder);
            $this->assertSame($engine, $givenEngine);
            $givenBuilder->where('tenant_id', 42);
        });

        $engine->deleteByFilter($builder);

        $this->assertCount(1, $observer->operations);
        $this->assertSame('delete_by_filter', $observer->operations[0]->operation);
        $this->assertSame('users_write', $observer->operations[0]->index);
    }

    public function testDeleteByFilterUsesAnExplicitIndex(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();
        $client->expects('index')->with('users_v2')->returns($index);
        $index->expects('deleteDocuments')
            ->with(['filter' => 'tenant_id=42'])
            ->returns(['taskUid' => 123]);
        $client->expects('waitForTask')
            ->with(123, 500_000, 5_000)
            ->returns(['status' => 'succeeded']);

        $engine = new MeilisearchEngine($client);
        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->expects('indexableAs')->never();
        $builder = (new Builder($model, ''))->within('users_v2')->where('tenant_id', 42);

        $engine->deleteByFilter($builder);
    }

    public function testDeleteByFilterTreatsAMissingIndexAsAlreadyDeleted(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();
        $client->expects('index')->with('users')->returns($index);
        $index->expects('deleteDocuments')
            ->with(['filter' => 'tenant_id=42'])
            ->returns(['taskUid' => 123]);
        $client->expects('waitForTask')
            ->with(123, 500_000, 5_000)
            ->returns([
                'status' => 'failed',
                'error' => ['code' => 'index_not_found'],
            ]);

        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->expects('indexableAs')->returns('users');
        $builder = (new Builder($model, ''))->where('tenant_id', 42);

        (new MeilisearchEngine($client))->deleteByFilter($builder);
    }

    public function testDeleteByFilterRejectsAnEmptyFilterBeforeIo(): void
    {
        $client = Double::for(Client::class)->strict();
        $client->expects('index')->never();
        $client->expects('waitForTask')->never();
        $engine = new MeilisearchEngine($client);
        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->expects('indexableAs')->never();
        $builder = new Builder($model, '');
        $prepared = false;
        Scout::prepareBuilderUsing(function () use (&$prepared): void {
            $prepared = true;
        });

        try {
            $engine->deleteByFilter($builder);
            $this->fail('Expected empty filter deletion to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Meilisearch filter deletion requires a non-empty filter.', $exception->getMessage());
        }

        $this->assertTrue($prepared);
    }

    public function testDeleteByFilterDoesNotNotifyObserversForAnEmptyFilter(): void
    {
        $client = Double::for(Client::class)->strict();
        $client->expects('index')->never();
        $client->expects('waitForTask')->never();
        $observer = Double::for(EngineOperationObserver::class)->strict();
        $observer->expects('starting')->never();
        $observer->expects('finished')->never();
        $runner = new EngineOperationRunner;
        $runner->observe($observer);
        $engine = (new MeilisearchEngine($client))->setOperationRunner($runner, 'meilisearch');
        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->expects('indexableAs')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Meilisearch filter deletion requires a non-empty filter.');

        $engine->deleteByFilter(new Builder($model, ''));
    }

    public function testDeleteByFilterRejectsAnUnsuccessfulTask(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();
        $client->expects('index')->with('users')->returns($index);
        $index->expects('deleteDocuments')->returns(['taskUid' => 123]);
        $client->expects('waitForTask')
            ->with(123, 500_000, 5_000)
            ->returns(['status' => 'failed']);

        $engine = new MeilisearchEngine($client);
        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('indexableAs')->returns('users');
        $builder = (new Builder($model, ''))->where('tenant_id', 42);

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIs('Meilisearch filter deletion did not complete successfully.');

        $engine->deleteByFilter($builder);
    }

    public function testDeleteByFilterPreservesSdkTimeouts(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();
        $timeout = new TimeOutException('Timed out waiting for deletion.');
        $client->expects('index')->with('users')->returns($index);
        $index->expects('deleteDocuments')->returns(['taskUid' => 123]);
        $client->expects('waitForTask')
            ->with(123, 500_000, 5_000)
            ->throws($timeout);

        $engine = new MeilisearchEngine($client);
        $model = Double::for(MeilisearchTestSearchableModel::class)->strict();
        $model->allows('indexableAs')->returns('users');
        $builder = (new Builder($model, ''))->where('tenant_id', 42);

        $this->expectExceptionObject($timeout);

        $engine->deleteByFilter($builder);
    }

    public function testCreateIndexCreatesNewIndex(): void
    {
        $client = Double::for(Client::class)->strict();

        $client->expects('getIndex')
            ->with('test_index')
            ->throws($this->apiException(404, 'index_not_found'));

        $taskInfo = ['taskUid' => 1, 'indexUid' => 'test_index', 'status' => 'enqueued'];
        $client->expects('createIndex')
            ->with('test_index', ['primaryKey' => 'id'])
            ->returns($taskInfo);

        $engine = new MeilisearchEngine($client);

        $result = $engine->createIndex('test_index', ['primaryKey' => 'id']);

        $this->assertSame($taskInfo, $result);
    }

    public function testCreateIndexReturnsExistingIndex(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $index->allows('getUid')->returns('test_index');

        $client->expects('getIndex')
            ->with('test_index')
            ->returns($index);

        $client->expects('createIndex')->never();

        $engine = new MeilisearchEngine($client);

        $result = $engine->createIndex('test_index');

        $this->assertSame($index, $result);
    }

    public function testCreateIndexPropagatesNonNotFoundLookupFailure(): void
    {
        $client = Double::for(Client::class)->strict();
        $exception = $this->apiException(401, 'invalid_api_key');

        $client->expects('getIndex')
            ->with('test_index')
            ->throws($exception);

        $client->expects('createIndex')->never();

        $engine = new MeilisearchEngine($client);

        $this->expectExceptionObject($exception);

        $engine->createIndex('test_index');
    }

    public function testCreateIndexReturnsIndexWhenCreationLosesRace(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $client->expects('getIndex')
            ->with('test_index')
            ->throws($this->apiException(404, 'index_not_found'));

        $client->expects('createIndex')
            ->with('test_index', [])
            ->throws($this->apiException(409, 'index_already_exists'));

        $client->expects('index')
            ->with('test_index')
            ->returns($index);

        $engine = new MeilisearchEngine($client);

        $this->assertSame($index, $engine->createIndex('test_index'));
    }

    public function testCreateIndexPropagatesOtherCreateConflict(): void
    {
        $client = Double::for(Client::class)->strict();
        $exception = $this->apiException(409, 'invalid_index_uid');

        $client->expects('getIndex')
            ->with('test_index')
            ->throws($this->apiException(404, 'index_not_found'));

        $client->expects('createIndex')
            ->with('test_index', [])
            ->throws($exception);

        $client->expects('index')->never();

        $engine = new MeilisearchEngine($client);

        $this->expectExceptionObject($exception);

        $engine->createIndex('test_index');
    }

    public function testDeleteIndexDeletesIndex(): void
    {
        $client = Double::for(Client::class)->strict();

        $client->expects('deleteIndex')
            ->with('test_index')
            ->returns(['taskUid' => 1]);

        $engine = new MeilisearchEngine($client);

        $result = $engine->deleteIndex('test_index');

        $this->assertEquals(['taskUid' => 1], $result);
    }

    public function testDeleteAllIndexesWithPrefixScopesToPrefixedUids(): void
    {
        $client = Double::for(Client::class)->strict();

        $testUsers = Double::for(Indexes::class)->strict();
        $testUsers->allows('getUid')->returns('test_users');
        $testUsers->expects('delete')->returns(['taskUid' => 1]);

        $testPosts = Double::for(Indexes::class)->strict();
        $testPosts->allows('getUid')->returns('test_posts');
        $testPosts->expects('delete')->returns(['taskUid' => 2]);

        $otherData = Double::for(Indexes::class)->strict();
        $otherData->allows('getUid')->returns('other_data');
        $otherData->expects('delete')->never();

        $results = Double::for(IndexesResults::class)->strict();
        $results->allows('getResults')->returns([$testUsers, $testPosts, $otherData]);

        $client->expects('getIndexes')->returns($results);

        $engine = new MeilisearchEngine($client);
        $engine->deleteAllIndexes('test_');
    }

    public function testDeleteAllIndexesWithNullPrefixDeletesEverything(): void
    {
        $client = Double::for(Client::class)->strict();

        $testUsers = Double::for(Indexes::class)->strict();
        $testUsers->allows('getUid')->returns('test_users');
        $testUsers->expects('delete');

        $testPosts = Double::for(Indexes::class)->strict();
        $testPosts->allows('getUid')->returns('test_posts');
        $testPosts->expects('delete');

        $otherData = Double::for(Indexes::class)->strict();
        $otherData->allows('getUid')->returns('other_data');
        $otherData->expects('delete');

        $results = Double::for(IndexesResults::class)->strict();
        $results->allows('getResults')->returns([$testUsers, $testPosts, $otherData]);

        $client->expects('getIndexes')->returns($results);

        $engine = new MeilisearchEngine($client);
        $engine->deleteAllIndexes(null);
    }

    public function testDeleteAllIndexesWithEmptyStringPrefixDeletesEverything(): void
    {
        $client = Double::for(Client::class)->strict();

        $testUsers = Double::for(Indexes::class)->strict();
        $testUsers->allows('getUid')->returns('test_users');
        $testUsers->expects('delete');

        $testPosts = Double::for(Indexes::class)->strict();
        $testPosts->allows('getUid')->returns('test_posts');
        $testPosts->expects('delete');

        $otherData = Double::for(Indexes::class)->strict();
        $otherData->allows('getUid')->returns('other_data');
        $otherData->expects('delete');

        $results = Double::for(IndexesResults::class)->strict();
        $results->allows('getResults')->returns([$testUsers, $testPosts, $otherData]);

        $client->expects('getIndexes')->returns($results);

        $engine = new MeilisearchEngine($client);
        $engine->deleteAllIndexes('');
    }

    public function testDeleteAllIndexesReturnsTasks(): void
    {
        $client = Double::for(Client::class)->strict();

        $testUsers = Double::for(Indexes::class)->strict();
        $testUsers->allows('getUid')->returns('test_users');
        $testUsers->allows('delete')->returns(['taskUid' => 1]);

        $testPosts = Double::for(Indexes::class)->strict();
        $testPosts->allows('getUid')->returns('test_posts');
        $testPosts->allows('delete')->returns(['taskUid' => 2]);

        $results = Double::for(IndexesResults::class)->strict();
        $results->allows('getResults')->returns([$testUsers, $testPosts]);

        $client->allows('getIndexes')->returns($results);

        $engine = new MeilisearchEngine($client);
        $tasks = $engine->deleteAllIndexes('test_');

        $this->assertEquals([['taskUid' => 1], ['taskUid' => 2]], $tasks);
    }

    public function testUpdateIndexSettingsWithEmbedders(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $client->expects('index')
            ->with('test_index')
            ->returns($index);

        $index->expects('updateSettings')
            ->with(['searchableAttributes' => ['title']]);

        $index->expects('updateEmbedders')
            ->with(['default' => ['source' => 'openAi']]);

        $engine = new MeilisearchEngine($client);
        $engine->updateIndexSettings('test_index', [
            'searchableAttributes' => ['title'],
            'embedders' => ['default' => ['source' => 'openAi']],
        ]);
    }

    public function testUpdateIndexSettingsWithoutEmbedders(): void
    {
        $client = Double::for(Client::class)->strict();
        $index = Double::for(Indexes::class)->strict();

        $client->expects('index')
            ->with('test_index')
            ->returns($index);

        $index->expects('updateSettings')
            ->with(['searchableAttributes' => ['title', 'body']]);

        $index->expects('updateEmbedders')->never();

        $engine = new MeilisearchEngine($client);
        $engine->updateIndexSettings('test_index', [
            'searchableAttributes' => ['title', 'body'],
        ]);
    }

    public function testConfigureSoftDeleteFilterAddsFilterableAttribute(): void
    {
        $client = Double::for(Client::class)->strict();
        $engine = new MeilisearchEngine($client);

        $settings = $engine->configureSoftDeleteFilter([
            'filterableAttributes' => ['status'],
        ]);

        $this->assertContains('__soft_deleted', $settings['filterableAttributes']);
        $this->assertContains('status', $settings['filterableAttributes']);
    }

    public function testEngineForwardsCallsToMeilisearchClient(): void
    {
        $client = Double::for(Client::class)->strict();
        $client->expects('health')
            ->returns(['status' => 'available']);

        $engine = new MeilisearchEngine($client);

        $result = $engine->health();

        $this->assertEquals(['status' => 'available'], $result);
    }

    public function testGetMeilisearchClientReturnsClient(): void
    {
        $client = Double::for(Client::class)->strict();
        $engine = new MeilisearchEngine($client);

        $this->assertSame($client, $engine->getMeilisearchClient());
    }

    public function testGenerateTenantTokenUsesTheExplicitParentIdentityAndSigner(): void
    {
        $client = new Client('http://localhost:7700', 'different-client-key');
        $engine = new MeilisearchEngine($client);
        $expiresAt = new DateTimeImmutable('+1 hour');
        $apiKey = 'explicit-parent-key';

        $token = $engine->generateTenantToken(
            ['users' => ['filter' => 'tenant_id = 42']],
            'parent-key-uid',
            $apiKey,
            $expiresAt,
        );
        [$encodedHeader, $encodedPayload, $signature] = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($encodedPayload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        $signedContent = $encodedHeader . '.' . $encodedPayload;
        $expectedSignature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $signedContent, $apiKey, true)
        ), '+/', '-_'), '=');
        $clientKeySignature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $signedContent, 'different-client-key', true)
        ), '+/', '-_'), '=');

        $this->assertSame('parent-key-uid', $payload['apiKeyUid']);
        $this->assertSame(['users' => ['filter' => 'tenant_id = 42']], $payload['searchRules']);
        $this->assertSame($expiresAt->getTimestamp(), $payload['exp']);
        $this->assertSame($expectedSignature, $signature);
        $this->assertNotSame($clientKeySignature, $signature);
    }

    #[DataProvider('emptyTenantTokenCredentials')]
    public function testGenerateTenantTokenRejectsEmptyCredentials(
        string $apiKeyUid,
        string $apiKey,
        string $message
    ): void {
        $client = Double::for(Client::class)->strict();
        $client->expects('generateTenantToken')->never();
        $client->expects('getKeys')->never();
        $engine = new MeilisearchEngine($client);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);

        $engine->generateTenantToken(['users' => ['filter' => 'tenant_id = 42']], $apiKeyUid, $apiKey);
    }

    /**
     * Provide empty Meilisearch tenant-token credentials.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function emptyTenantTokenCredentials(): array
    {
        return [
            'empty UID' => ['', 'explicit-parent-key', 'Meilisearch tenant tokens require a non-empty API key UID.'],
            'empty key' => ['parent-key-uid', '', 'Meilisearch tenant tokens require a non-empty API key.'],
        ];
    }

    protected function apiException(int $status, string $code): ApiException
    {
        return new ApiException(new Response($status), [
            'message' => $code,
            'code' => $code,
        ]);
    }

    /**
     * Create an engine with embedding settings for the given model.
     *
     * @param array<string, mixed> $embedding
     * @param class-string<Model> $model
     */
    protected function engineWithEmbedding(
        Client $client,
        array $embedding,
        string $model = SearchableModelWithPrecomputedEmbedding::class
    ): MeilisearchEngine {
        return new MeilisearchEngine($client, false, [
            'model-settings' => [
                $model => ['embedding' => $embedding],
            ],
        ]);
    }
}

class MeilisearchEngineOperationObserver implements EngineOperationObserver
{
    /**
     * The observed operations.
     *
     * @var array<EngineOperation>
     */
    public array $operations = [];

    public function starting(EngineOperation $operation): mixed
    {
        $this->operations[] = $operation;

        return null;
    }

    public function finished(
        EngineOperation $operation,
        mixed $token,
        ?Throwable $exception
    ): void {
    }
}

/**
 * Test model for MeilisearchEngine tests.
 */
class MeilisearchTestSearchableModel extends Model
{
    use Searchable;

    protected array $guarded = [];

    public bool $timestamps = false;
}

/**
 * Test model with soft deletes for MeilisearchEngine tests.
 */
class MeilisearchTestSoftDeleteModel extends Model
{
    use Searchable;
    use SoftDeletes;

    protected array $guarded = [];

    public bool $timestamps = false;
}

class MeilisearchTestChirpModel extends Model
{
    protected ?string $table = 'chirps';

    protected array $guarded = [];

    public bool $timestamps = false;

    public function getScoutKeyName(): string
    {
        return 'scout_id';
    }

    public function indexableAs(): string
    {
        return $this->getTable();
    }
}

enum MeilisearchStringStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

enum MeilisearchIntegerPriority: int
{
    case Low = 2;
    case High = 1;
}
