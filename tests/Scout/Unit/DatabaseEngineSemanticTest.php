<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Ai\AiServiceProvider;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\PostgresConnection;
use Hypervel\Pagination\Paginator;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Hypervel\Scout\EngineOperation;
use Hypervel\Scout\EngineOperationRunner;
use Hypervel\Scout\Engines\DatabaseEngine;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PDO;

#[WithConfig('ai.caching.embeddings.store', 'array')]
class DatabaseEngineSemanticTest extends TestCase
{
    /**
     * Register the embedding provider used by semantic searches.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [AiServiceProvider::class];
    }

    /**
     * Set up pagination defaults.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Paginator::currentPageResolver(fn (): int => 1);
        Paginator::currentPathResolver(fn (): string => '/');
    }

    public function testUnnamedConnectionsKeepTheConfiguredImportConcurrency(): void
    {
        $this->assertSame(50, (new DatabaseEngine)->importConcurrency($this->model(), 50));
    }

    public function testItBuildsAFilteredPostgresVectorSimilarityQuery(): void
    {
        $model = $this->model();

        Embeddings::fake([[[0.1, 0.2]]])->preventStrayEmbeddings();

        $query = $this->engine()->semanticQuery(
            (new Builder($model, 'semantic query'))
                ->semantic(minSimilarity: 0.4)
                ->where('user_id', 1)
        );

        $this->assertSame(
            'select * from "documents" where ("documents"."embedding" <=> ?) <= ? and "user_id" = ? order by ("documents"."embedding" <=> ?) asc, "documents"."id" asc',
            $query->toSql()
        );
        $this->assertSame(['[0.1,0.2]', 0.6, 1, '[0.1,0.2]'], $query->getBindings());
        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['semantic query']);

        $cachedQuery = $this->engine()->semanticQuery((new Builder($model, 'semantic query'))->semantic());
        $this->assertSame(['[0.1,0.2]', 0.4, '[0.1,0.2]'], $cachedQuery->getBindings());
    }

    public function testSemanticSearchCallbacksAreAppliedAfterTheVectorConstraint(): void
    {
        $model = $this->model();

        Embeddings::fake([[[0.1, 0.2]]])->preventStrayEmbeddings();
        $query = $this->engine()->semanticQuery(
            (new Builder($model, 'semantic query', function (EloquentBuilder $query): void {
                $query->orWhere('name', 'Fallback');
            }))->semantic()
        );

        $this->assertSame(
            'select * from "documents" where ("documents"."embedding" <=> ?) <= ? or "name" = ? order by ("documents"."embedding" <=> ?) asc, "documents"."id" asc',
            $query->toSql()
        );
        $this->assertSame(['[0.1,0.2]', 0.4, 'Fallback', '[0.1,0.2]'], $query->getBindings());
    }

    public function testSemanticSearchCallbacksCanOverrideTheScoutResultLimit(): void
    {
        $model = $this->model();

        Embeddings::fake([[[0.1, 0.2]]])->preventStrayEmbeddings();
        $query = $this->engine()->semanticQuery(
            (new Builder($model, 'semantic query', function (EloquentBuilder $query): void {
                $query->take(1);
            }))->semantic()->take(10)
        );

        $this->assertSame(1, $query->getQuery()->limit);
    }

    public function testSemanticPaginationRespectsTheScoutResultLimit(): void
    {
        $model = $this->model();
        $model->getConnection()->statement('create table documents (id integer primary key, name varchar(255))');
        $model->getConnection()->table('documents')->insert([
            ['id' => 1, 'name' => 'One'],
            ['id' => 2, 'name' => 'Two'],
            ['id' => 3, 'name' => 'Three'],
            ['id' => 4, 'name' => 'Four'],
        ]);
        $engine = new class extends DatabaseEngine {
            /**
             * Build an ordered query without requiring the vector extension.
             */
            protected function buildSemanticSearchQuery(Builder $builder): EloquentBuilder
            {
                return $builder->model->newQuery()->orderBy('id');
            }
        };

        $results = $engine->paginateUsingDatabase(
            (new Builder($model, 'query'))->semantic()->take(3),
            2,
            'page',
            2
        );

        $this->assertSame(3, $results->total());
        $this->assertSame([3], $results->getCollection()->modelKeys());
    }

    public function testItPersistsAPrecomputedEmbeddingWithoutModelEvents(): void
    {
        $model = $this->model();

        $model->getConnection()->statement('create table documents (id integer primary key, name varchar(255), embedding text)');
        $model->getConnection()->table('documents')->insert(['id' => 1, 'name' => 'Document']);

        $model->forceFill(['id' => 1, 'name' => 'Document', 'embedding' => [0.3, 0.7]]);
        $model->exists = true;
        DatabaseSemanticModel::updating(fn (): never => $this->fail('Embedding updates must not dispatch model events.'));

        (new DatabaseEngine)->update($model->newCollection([$model]));

        $this->assertSame(
            '[0.3,0.7]',
            $model->getConnection()->table('documents')->where('id', 1)->value('embedding')
        );
    }

    public function testItGeneratesAndPersistsADatabaseEmbedding(): void
    {
        $model = $this->model();

        $model->getConnection()->statement('create table documents (id integer primary key, name varchar(255), embedding text)');
        $model->getConnection()->table('documents')->insert(['id' => 1, 'name' => 'Generate this']);
        Embeddings::fake([[[0.2, 0.8]]])->preventStrayEmbeddings();

        $model->forceFill(['id' => 1, 'name' => 'Generate this']);
        $model->exists = true;

        $observer = m::mock(EngineOperationObserver::class);
        $observer->shouldReceive('starting')->once()->with(m::on(
            fn (EngineOperation $operation): bool => $operation->operation === 'update'
                && $operation->engineName === 'database'
                && $operation->modelCount === 1
                && $operation->index === 'documents'
        ))->andReturn(null);
        $observer->shouldReceive('finished')->once()->with(m::type(EngineOperation::class), null, null);
        $runner = new EngineOperationRunner;
        $runner->observe($observer);

        (new DatabaseEngine)->setOperationRunner($runner, 'database')->runUpdate($model->newCollection([$model]));

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['Generate this']);
        $this->assertSame(
            '[0.2,0.8]',
            $model->getConnection()->table('documents')->where('id', 1)->value('embedding')
        );
    }

    /**
     * Create a model using PostgreSQL grammar with an in-memory database.
     */
    protected function model(): DatabaseSemanticModel
    {
        $connection = new PostgresConnection(new PDO('sqlite::memory:'), '', '', ['driver' => 'pgsql']);
        $resolver = m::mock(ConnectionResolverInterface::class, ['connection' => $connection]);
        Model::setConnectionResolver($resolver);

        return new DatabaseSemanticModel;
    }

    /**
     * Expose the semantic query for SQL assertions.
     */
    protected function engine(): DatabaseEngine
    {
        return new class extends DatabaseEngine {
            /**
             * Build the semantic search query.
             */
            public function semanticQuery(Builder $builder): EloquentBuilder
            {
                return $this->buildSemanticSearchQuery($builder);
            }
        };
    }
}

class DatabaseSemanticModel extends Model
{
    protected ?string $table = 'documents';

    protected array $guarded = [];

    /**
     * Get the index used to store the model.
     */
    public function indexableAs(): string
    {
        return $this->getTable();
    }

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }

    /**
     * Get the embedding or its source text.
     */
    public function toSearchableEmbedding(): array|string
    {
        return $this->embedding ?? $this->name;
    }

    /**
     * Get the value used to index the model.
     */
    public function getScoutKey(): mixed
    {
        return $this->getKey();
    }
}
