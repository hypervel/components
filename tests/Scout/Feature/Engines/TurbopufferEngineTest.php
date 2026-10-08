<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Feature\Engines;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Telescope\TelescopeTag;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\EngineOperation;
use Hypervel\Scout\EngineOperationRunner;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Engines\TurbopufferEngine;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Scout;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Scout\Services\Turbopuffer\TurbopufferClient;
use Hypervel\Support\ClassInvoker;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModelWithNativeEmbedding;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModelWithPrecomputedEmbedding;
use Hypervel\Tests\Scout\Fixtures\Models\SoftDeletableSearchableModel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

class TurbopufferEngineTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            ScoutServiceProvider::class,
        ];
    }

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('scout.driver', 'turbopuffer');
        $config->set('scout.prefix', '');
        $config->set('scout.soft_delete', true);
        $config->set('scout.turbopuffer', [
            'api_key' => 'tpuf-test-key',
            'region' => 'gcp-us-central1',
            'base_url' => 'https://turbopuffer.test',
            'retries' => 0,
            'model-settings' => [
                SearchableModelWithPrecomputedEmbedding::class => [
                    'searchable-attributes' => [
                        'name' => 3,
                        'description' => 1,
                    ],
                    'schema' => [
                        'name' => ['type' => 'string', 'full_text_search' => true],
                        'description' => ['type' => 'string', 'full_text_search' => true],
                    ],
                ],
            ],
        ]);
    }

    public function testDriverIsRegistered(): void
    {
        $this->assertInstanceOf(
            TurbopufferEngine::class,
            $this->app->make(EngineManager::class)->engine()
        );
    }

    public function testUpdateUpsertsModelsWithSchemaAndScoutIds(): void
    {
        Http::fake(['*' => Http::response(['rows_affected' => 1])]);

        $model = new SearchableModelWithPrecomputedEmbedding(['id' => 10, 'name' => 'Taylor']);

        $this->engine()->update($model->newCollection([$model]));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://turbopuffer.test/v2/namespaces/table'
                && $request->hasHeader('Authorization', 'Bearer tpuf-test-key')
                && $request['upsert_rows'] === [['id' => 10, 'name' => 'Taylor']]
                && $request['schema'] === [
                    'name' => ['type' => 'string', 'full_text_search' => true],
                    'description' => ['type' => 'string', 'full_text_search' => true],
                ];
        });
    }

    public function testUpdateAddsSoftDeleteMetadata(): void
    {
        Http::fake(['*' => Http::response(['rows_affected' => 1])]);

        $model = new SoftDeletableSearchableModel;
        $model->setAttribute('id', 10);

        $this->engine()->update($model->newCollection([$model]));

        Http::assertSent(fn (Request $request): bool => $request['upsert_rows'][0] === [
            'id' => 10,
            'title' => null,
            'body' => null,
            '__soft_deleted' => 0,
        ]);
    }

    public function testUpdatePreparesRowsAndIndexSettingsBeforeWriting(): void
    {
        Http::fake(['*' => Http::response(['rows_affected' => 1])]);

        $model = new SearchableModelWithPrecomputedEmbedding(['id' => 10, 'name' => 'Taylor']);
        $engine = $this->engine();

        Scout::prepareSearchableDocumentUsing(
            fn (array $document, Model $givenModel, Engine $givenEngine): array => [...$document, 'account_id' => 42]
        );
        Scout::prepareIndexSettingsUsing(function (array $settings, ?Model $givenModel, Engine $givenEngine, string $index) use ($model, $engine): array {
            $this->assertSame($model, $givenModel);
            $this->assertSame($engine, $givenEngine);
            $this->assertSame('table', $index);

            $settings['schema']['account_id'] = ['type' => 'uint', 'filterable' => true];

            return $settings;
        });

        $engine->update($model->newCollection([$model]));

        Http::assertSent(fn (Request $request): bool => $request['upsert_rows'] === [['id' => 10, 'name' => 'Taylor', 'account_id' => 42]]
            && $request['schema']['account_id'] === ['type' => 'uint', 'filterable' => true]);
    }

    public function testSemanticSearchReportsThatGeneratedEmbeddingsAreUnavailable(): void
    {
        $this->configureEmbeddings();
        Http::preventStrayRequests();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIsOrContains('AI-generated embeddings are not available in Hypervel.');

        $this->engine()->search(
            (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))->semantic()
        );
    }

    public function testUpdateUpsertsPrecomputedEmbeddingsBeforePreparingRows(): void
    {
        $this->configureEmbeddings(['attribute' => 'vector']);
        Http::fake(['*' => Http::response(['rows_affected' => 2])]);

        $first = new SearchableModelWithPrecomputedEmbedding(['id' => 10, 'name' => 'First']);
        $first->setAttribute('embedding', [0.1, 0.2]);

        $second = new SearchableModelWithPrecomputedEmbedding(['id' => 20, 'name' => 'Second']);
        $second->setAttribute('embedding', [0.3, 0.4]);

        $preparedVectors = [];

        Scout::prepareSearchableDocumentUsing(function (array $document) use (&$preparedVectors): array {
            $preparedVectors[] = $document['vector'];

            return $document;
        });

        $this->engine()->update($first->newCollection([$first, $second]));

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $preparedVectors);

        Http::assertSent(fn (Request $request): bool => $request['upsert_rows'] === [
            ['id' => 10, 'name' => 'First', 'embedding' => [0.1, 0.2], 'vector' => [0.1, 0.2]],
            ['id' => 20, 'name' => 'Second', 'embedding' => [0.3, 0.4], 'vector' => [0.3, 0.4]],
        ]
            && $request['schema']['vector'] === ['type' => '[2]f32', 'ann' => true]
            && $request['distance_metric'] === 'cosine_distance');
    }

    public function testUpdateCanUseTurbopufferNativeEmbeddings(): void
    {
        $this->configureNativeEmbeddings();
        Http::fake(['*' => Http::response(['rows_affected' => 1])]);

        $model = new SearchableModelWithNativeEmbedding([
            'id' => 10,
            'name' => 'Native embedding source',
            'embedding' => [0.1, 0.2],
        ]);

        $this->engine()->update($model->newCollection([$model]));

        Http::assertSent(function (Request $request): bool {
            $this->assertSame([[
                'id' => 10,
                'name' => 'Native embedding source',
            ]], $request['upsert_rows']);
            $this->assertEquals([
                'type' => 'string',
                'full_text_search' => true,
                'embed' => [
                    'model' => 'turbopuffer/native-test',
                    'dims' => 2,
                    'attribute' => 'embedding',
                ],
            ], $request['schema']['name']);
            $this->assertSame('cosine_distance', $request['distance_metric']);

            return true;
        });
    }

    public function testDeleteSendsOneBatchedRequest(): void
    {
        Http::fake(['*' => Http::response(['rows_affected' => 2])]);

        $models = (new SearchableModelWithPrecomputedEmbedding)->newCollection([
            new SearchableModelWithPrecomputedEmbedding(['id' => 10]),
            new SearchableModelWithPrecomputedEmbedding(['id' => 20]),
        ]);

        $this->engine()->delete($models);

        Http::assertSent(fn (Request $request): bool => $request['deletes'] === [10, 20]);
    }

    public function testDeleteByFilterPreparesTheBuilderAndContinuesUntilNoMatchesRemain(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['rows_affected' => 3, 'rows_remaining' => true])
            ->push(['rows_affected' => 1, 'rows_remaining' => false])]);

        Scout::prepareBuilderUsing(function (Builder $builder): void {
            $builder->where('account_id', 42);
        });

        $observer = $this->observeOperations();

        $this->engine()->deleteByFilter(
            (new Builder(new SearchableModelWithPrecomputedEmbedding, ''))
                ->options(['filters' => ['status', 'Eq', 'archived']])
        );

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://turbopuffer.test/v2/namespaces/table'
            && $request['delete_by_filter'] === ['And', [['status', 'Eq', 'archived'], ['account_id', 'Eq', 42]]]
            && $request['delete_by_filter_allow_partial'] === true);
        $this->assertSame([['delete_by_filter', 'table']], $observer->operations);
        $this->assertSame([null], $observer->exceptions);
    }

    public function testDeleteByFilterUsesAnExplicitNamespace(): void
    {
        Http::fake(['*' => Http::response(['rows_affected' => 1])]);

        $this->engine()->deleteByFilter(
            (new Builder(new SearchableModelWithPrecomputedEmbedding, ''))->within('archive')->where('status', 'archived')
        );

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://turbopuffer.test/v2/namespaces/archive');
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('emptyDeletionFilters')]
    public function testDeleteByFilterRejectsAnEmptyFilterBeforeIo(array $options): void
    {
        Http::preventStrayRequests();
        $observer = $this->observeOperations();

        try {
            $this->engine()->deleteByFilter((new Builder(new SearchableModelWithPrecomputedEmbedding, ''))->options($options));

            $this->fail('Expected filter deletion to reject an empty filter.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Turbopuffer filter deletion requires a non-empty filter.', $exception->getMessage());
        }

        $this->assertSame([], $observer->operations);
    }

    /**
     * Get the deletion options that contain no filter.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function emptyDeletionFilters(): array
    {
        return [
            'no filters' => [[]],
            'empty native filters' => [['filters' => []]],
        ];
    }

    public function testDeleteByFilterReportsAFailureAfterPartialCompletion(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['rows_affected' => 3, 'rows_remaining' => true])
            ->push(['status' => 'error'], 500)]);

        $observer = $this->observeOperations();

        try {
            $this->engine()->deleteByFilter(
                (new Builder(new SearchableModelWithPrecomputedEmbedding, ''))->where('status', 'archived')
            );

            $this->fail('Expected the failed continuation to be reported.');
        } catch (RequestException $exception) {
            $this->assertSame(500, $exception->response->status());
        }

        Http::assertSentCount(2);
        $this->assertSame([['delete_by_filter', 'table']], $observer->operations);
        $this->assertSame([RequestException::class], $observer->exceptions);
    }

    public function testMissingNamespacesAreTreatedAsEmpty(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'error' => 'namespace not found'], 404)]);

        $engine = $this->engine();
        $model = new SearchableModelWithPrecomputedEmbedding(['id' => 10]);

        $engine->delete($model->newCollection([$model]));
        $engine->deleteByFilter((new Builder($model, ''))->where('status', 'archived'));
        $engine->flush($model);

        $this->assertSame(['rows' => [], 'total' => 0], $engine->search(new Builder($model, 'hypervel')));
        $this->assertSame(['rows' => [], 'total' => 0], $engine->paginate(new Builder($model, 'hypervel'), 15, 1));
        Http::assertSentCount(6);
    }

    public function testFailuresOtherThanAMissingNamespaceAreThrown(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error'], 401)]);

        $this->expectException(RequestException::class);

        $this->engine()->flush(new SearchableModelWithPrecomputedEmbedding);
    }

    public function testSearchBuildsWeightedBm25AndScoutFilters(): void
    {
        Http::fake(['*' => Http::response([
            'rows' => [['id' => 10, '$dist' => 1.25]],
            'billing' => ['billable_logical_bytes_queried' => 100],
        ])]);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'hypervel'))
            ->where('status', 'published')
            ->where('age', '>=', 18)
            ->whereIn('language', ['en', 'fr'])
            ->whereNotIn('category', ['archived'])
            ->take(25);

        $results = $this->engine()->search($builder);

        $this->assertSame(10, $results['rows'][0]['id']);
        $this->assertSame(100, $results['billing']['billable_logical_bytes_queried']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://turbopuffer.test/v2/namespaces/table/query'
                && $request['rank_by'] === ['Sum', [
                    ['Product', 3, ['name', 'BM25', 'hypervel']],
                    ['description', 'BM25', 'hypervel'],
                ]]
                && $request['filters'] === ['And', [
                    ['status', 'Eq', 'published'],
                    ['age', 'Gte', 18],
                    ['language', 'In', ['en', 'fr']],
                    ['category', 'NotIn', ['archived']],
                ]]
                && $request['limit'] === 25;
        });
    }

    public function testSearchAcceptsANativeVectorRankingExpression(): void
    {
        Http::fake(['*' => Http::response(['rows' => []])]);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, ''))
            ->options([
                'rank_by' => ['embedding', 'ANN', [0.1, 0.2]],
                'include_attributes' => ['name'],
                'consistency' => ['level' => 'eventual'],
            ]);

        $this->engine()->search($builder);

        Http::assertSent(fn (Request $request): bool => $request['rank_by'] === ['embedding', 'ANN', [0.1, 0.2]]
            && $request['include_attributes'] === ['name', 'id']
            && $request['consistency'] === ['level' => 'eventual']);
    }

    public function testSemanticSearchCanUseTurbopufferNativeQueryEmbeddings(): void
    {
        $this->configureNativeEmbeddings();
        Http::fake(['*' => Http::response(['rows' => [['id' => 10, '$dist' => 0.1]]])]);

        $results = $this->engine()->search(
            (new Builder(new SearchableModelWithNativeEmbedding, 'conceptual query'))
                ->semantic()
                ->where('status', 'published')
                ->take(10)
        );

        $this->assertSame(10, $results['rows'][0]['id']);

        Http::assertSent(fn (Request $request): bool => $request['rank_by'] === [
            'name',
            'ANN',
            ['Embed', 'conceptual query'],
        ] && $request['filters'] === ['status', 'Eq', 'published']
            && $request['limit'] === 10);
    }

    public function testHybridSearchCanUseTurbopufferNativeQueryEmbeddings(): void
    {
        $this->configureNativeEmbeddings();
        Http::fake(['*' => Http::response(['results' => [['rows' => []]]])]);

        $this->engine()->search(
            (new Builder(new SearchableModelWithNativeEmbedding, 'combined query'))->hybrid()
        );

        Http::assertSent(fn (Request $request): bool => $request['queries'][1]['rank_by'] === [
            'name',
            'ANN',
            ['Embed', 'combined query'],
        ]);
    }

    public function testNativeEmbeddingsAcceptAStringEmbedSchema(): void
    {
        $this->configureNativeEmbeddings();

        $config = config('scout.turbopuffer');
        $config['model-settings'][SearchableModelWithNativeEmbedding::class]['schema']['name']['embed'] = 'turbopuffer/native-test';
        config()->set('scout.turbopuffer', $config);

        Http::fake(['*' => Http::response(['rows' => []])]);

        $this->engine()->search(
            (new Builder(new SearchableModelWithNativeEmbedding, 'conceptual query'))->semantic()
        );

        Http::assertSent(fn (Request $request): bool => $request['rank_by'] === [
            'name',
            'ANN',
            ['Embed', 'conceptual query'],
        ]);
    }

    public function testNativeEmbeddingsRequireAnEmbedSchema(): void
    {
        $this->configureNativeEmbeddings();

        $config = config('scout.turbopuffer');
        unset($config['model-settings'][SearchableModelWithNativeEmbedding::class]['schema']['name']['embed']);
        config()->set('scout.turbopuffer', $config);

        Http::preventStrayRequests();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIsOrContains('require a valid [embed] schema configuration');

        $this->engine()->search(
            (new Builder(new SearchableModelWithNativeEmbedding, 'conceptual query'))->semantic()
        );
    }

    public function testHybridSearchUsesWeightedRrfAndNormalizesResults(): void
    {
        $this->configureNativeEmbeddings();
        Http::fake(['*' => Http::response([
            'results' => [[
                'rows' => [
                    ['id' => 20, '$dist' => 0.03],
                    ['id' => 10, '$dist' => 0.02],
                    ['id' => 30, '$dist' => 0.01],
                ],
            ]],
        ])]);

        $builder = (new Builder(new SearchableModelWithNativeEmbedding, 'combined query'))
            ->hybrid(textWeight: 1, semanticWeight: 2)
            ->where('status', 'published')
            ->options([
                'include_attributes' => ['name'],
                'consistency' => ['level' => 'eventual'],
            ])
            ->take(2);

        $results = $this->engine()->search($builder);

        $this->assertSame([20, 10], array_column($results['rows'], 'id'));
        $this->assertSame(2, $results['total']);

        Http::assertSent(function (Request $request): bool {
            $queries = $request['queries'];

            return $request['consistency'] === ['level' => 'eventual']
                && $request['rerank_by'] === ['RRF', ['weights' => [1, 2]]]
                && $queries[0]['rank_by'] === ['Sum', [
                    ['Product', 3, ['name', 'BM25', 'combined query']],
                    ['description', 'BM25', 'combined query'],
                ]]
                && $queries[1]['rank_by'] === ['name', 'ANN', ['Embed', 'combined query']]
                && $queries[0]['filters'] === ['status', 'Eq', 'published']
                && $queries[1]['filters'] === ['status', 'Eq', 'published']
                && $queries[0]['include_attributes'] === ['name', 'id']
                && $queries[1]['include_attributes'] === ['name', 'id']
                && $queries[0]['limit'] === 2
                && $queries[1]['limit'] === 2;
        });
    }

    public function testMatchAllSearchUsesTheRequestedOrder(): void
    {
        Http::fake(['*' => Http::response(['rows' => []])]);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, '*'))->orderByDesc('created_at');

        $this->engine()->search($builder);

        Http::assertSent(fn (Request $request): bool => $request['rank_by'] === ['created_at', 'desc']);
    }

    public function testPaginateSlicesTheRankedWindowAndReportsACappedCountOfTextMatches(): void
    {
        Http::fake(function (Request $request): PromiseInterface {
            if (isset($request['aggregate_by'])) {
                return Http::response(['aggregations' => ['count' => 12000]]);
            }

            return Http::response(['rows' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
                ['id' => 4],
            ]]);
        });

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'hypervel'))->where('status', 'published');
        $results = $this->engine()->paginate($builder, 2, 2);

        $this->assertSame([3, 4], array_column($results['rows'], 'id'));
        $this->assertSame(10000, $results['total']);

        Http::assertSent(fn (Request $request): bool => isset($request['aggregate_by'])
            && $request['filters'] === ['And', [
                ['status', 'Eq', 'published'],
                ['Or', [
                    ['name', 'ContainsAnyToken', 'hypervel'],
                    ['description', 'ContainsAnyToken', 'hypervel'],
                ]],
            ]]);
    }

    public function testPaginateCountsEveryFilteredCandidateForSemanticSearches(): void
    {
        $this->configureNativeEmbeddings();
        Http::fake(function (Request $request): PromiseInterface {
            if (isset($request['aggregate_by'])) {
                return Http::response(['aggregations' => ['count' => 2]]);
            }

            return Http::response(['rows' => [
                ['id' => 1],
                ['id' => 2],
            ]]);
        });

        $results = $this->engine()->paginate(
            (new Builder(new SearchableModelWithNativeEmbedding, 'semantic query'))->semantic()->where('status', 'published'),
            1,
            2
        );

        $this->assertSame([2], array_column($results['rows'], 'id'));
        $this->assertSame(2, $results['total']);

        Http::assertSent(fn (Request $request): bool => isset($request['aggregate_by'])
            && $request['filters'] === ['status', 'Eq', 'published']);
    }

    public function testPaginationRejectsWindowsAboveTurbopufferLimit(): void
    {
        Http::preventStrayRequests();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIsOrContains('10,000');

        $this->engine()->paginate(new Builder(new SearchableModelWithPrecomputedEmbedding, 'hypervel'), 100, 101);
    }

    public function testPaginationReturnsTheFinalPartialPageWithinTurbopufferLimit(): void
    {
        Http::fake(function (Request $request): PromiseInterface {
            if (isset($request['aggregate_by'])) {
                return Http::response(['aggregations' => ['count' => 12000]]);
            }

            return Http::response(['rows' => array_map(fn (int $id): array => ['id' => $id], range(1, 10000))]);
        });

        $results = $this->engine()->paginate(new Builder(new SearchableModelWithPrecomputedEmbedding, 'hypervel'), 15, 667);

        $this->assertSame(range(9991, 10000), array_column($results['rows'], 'id'));
        $this->assertSame(10000, $results['total']);

        Http::assertSent(fn (Request $request): bool => ! isset($request['aggregate_by']) && $request['limit'] === 10000);
    }

    public function testFlushDeletesTheNamespace(): void
    {
        Http::fake(['*' => Http::response([])]);

        $this->engine()->flush(new SearchableModelWithPrecomputedEmbedding);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://turbopuffer.test/v2/namespaces/table');
    }

    public function testCreateIndexIsNotSupported(): void
    {
        $this->expectException(NotSupportedException::class);

        $this->engine()->createIndex('table');
    }

    public function testAnIndexBuildingResponseThrowsAScoutException(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIsOrContains('still building');

        $this->engine()->search(new Builder(new SearchableModelWithPrecomputedEmbedding, 'hypervel'));
    }

    public function testInvalidNamespacesAreRejectedBeforeARequestIsSent(): void
    {
        Http::preventStrayRequests();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIsOrContains('Invalid Turbopuffer namespace');

        $this->engine()->search(
            (new Builder(new SearchableModelWithPrecomputedEmbedding, 'hypervel'))->within('invalid namespace')
        );
    }

    public function testTransientFailuresAreRetried(): void
    {
        config()->set('scout.turbopuffer.retries', 1);
        Sleep::fake();
        Http::fake(['*' => Http::sequence()
            ->push(['status' => 'error'], 503)
            ->push(['rows' => [['id' => 10]]])]);

        $results = $this->engine()->search(new Builder(new SearchableModelWithPrecomputedEmbedding, 'hypervel'));

        $this->assertSame([['id' => 10]], $results['rows']);
        Http::assertSentCount(2);
    }

    public function testRequestsAreTaggedForTelescope(): void
    {
        $request = (new ClassInvoker($this->app->make(TurbopufferClient::class)))->pendingRequest();

        $this->assertSame([TelescopeTag::Scout, TelescopeTag::Turbopuffer], $request->getOptions()['telescope_tags']);
    }

    /**
     * Get the configured Turbopuffer engine.
     */
    protected function engine(): TurbopufferEngine
    {
        return $this->app->make(EngineManager::class)->engine('turbopuffer');
    }

    /**
     * Observe the Scout engine operations run during the test.
     */
    protected function observeOperations(): TurbopufferOperationObserver
    {
        $observer = new TurbopufferOperationObserver;

        $this->app->make(EngineOperationRunner::class)->observe($observer);

        return $observer;
    }

    /**
     * Configure precomputed embeddings for the searchable model fixture.
     *
     * @param array<string, mixed> $overrides
     */
    protected function configureEmbeddings(array $overrides = []): void
    {
        $config = config('scout.turbopuffer');
        $settings = $config['model-settings'][SearchableModelWithPrecomputedEmbedding::class];

        $settings['embedding'] = array_merge([
            'attribute' => 'embedding',
            'dimensions' => 2,
        ], $overrides);
        $settings['schema'][$settings['embedding']['attribute']] = ['type' => '[2]f32', 'ann' => true];

        $config['model-settings'][SearchableModelWithPrecomputedEmbedding::class] = $settings;

        config()->set('scout.turbopuffer', $config);
    }

    /**
     * Configure Turbopuffer's native embeddings for the native embedding model fixture.
     */
    protected function configureNativeEmbeddings(): void
    {
        $config = config('scout.turbopuffer');
        $settings = $config['model-settings'][SearchableModelWithPrecomputedEmbedding::class];

        $settings['embedding'] = [
            'driver' => 'turbopuffer',
            'attribute' => 'name',
        ];
        $settings['schema']['name']['embed'] = [
            'model' => 'turbopuffer/native-test',
            'dimensions' => '2',
            'attribute' => 'embedding',
        ];

        $config['model-settings'][SearchableModelWithNativeEmbedding::class] = $settings;

        config()->set('scout.turbopuffer', $config);
    }
}

class TurbopufferOperationObserver implements EngineOperationObserver
{
    /**
     * The observed operation names and indexes.
     *
     * @var array<int, array{string, string}>
     */
    public array $operations = [];

    /**
     * The exception class reported for each finished operation.
     *
     * @var array<int, null|class-string<Throwable>>
     */
    public array $exceptions = [];

    /**
     * Start observing an engine operation.
     */
    public function starting(EngineOperation $operation): mixed
    {
        $this->operations[] = [$operation->operation, $operation->index];

        return null;
    }

    /**
     * Finish observing an engine operation.
     */
    public function finished(EngineOperation $operation, mixed $token, ?Throwable $exception): void
    {
        $this->exceptions[] = $exception === null ? null : $exception::class;
    }
}
