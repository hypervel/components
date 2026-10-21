<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Database\Postgres;

use Hypervel\Ai\AiServiceProvider;
use Hypervel\Ai\Embeddings;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Casts\AsVector;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

#[RequiresDatabase('pgsql')]
class DatabaseVectorSearchTest extends ScoutTestCase
{
    /**
     * Register Scout and its optional embedding provider.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [...parent::getPackageProviders($app), AiServiceProvider::class];
    }

    /**
     * Configure database search with iterative HNSW scanning.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('scout.driver', 'database');
        $config->set('ai.caching.embeddings.store', 'array');
        $config->set('database.connections.' . $config->string('database.default') . '.server_options', [
            'hnsw.iterative_scan' => 'strict_order',
        ]);
    }

    /**
     * Require the optional extension before refreshing the test database.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $extension = DB::table('pg_available_extensions')->where('name', 'vector')->first();
        $version = $extension?->installed_version ?? $extension?->default_version;

        if ($version === null || version_compare($version, '0.8.0', '<')) {
            $this->markTestSkipped('Requires pgvector 0.8 or later.');
        }
    }

    /**
     * Add a transactional vector index to the shared model fixture.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::ensureVectorExtensionExists();
        Schema::table('searchable_models', function (Blueprint $table): void {
            $table->vector('embedding', dimensions: 3)->nullable();
            $table->vectorIndex('embedding');
        });
    }

    public function testIndexedSemanticAndHybridSearchRespectPaginationAndProjection(): void
    {
        $rows = [];

        for ($id = 1; $id <= 12; ++$id) {
            $rows[] = ['id' => $id, 'title' => 'Document ' . $id, 'embedding' => json_encode([1, $id / 100, 0])];
        }

        DB::table('searchable_models')->insert($rows);
        Embeddings::fake([[[1, 0, 0]]])->preventStrayEmbeddings();

        // Exercise the real index with a small fixture and an intentionally small initial candidate list.
        DB::statement('SET LOCAL enable_seqscan = off');
        DB::statement('SET LOCAL hnsw.ef_search = 5');
        DB::enableQueryLog();

        $query = VectorSearchModel::search('concept')->query(
            fn (Builder $query): Builder => $query->select(['searchable_models.id', 'title'])
        );
        $page = (clone $query)->semantic()->paginate(5, page: 2);

        $this->assertSame(12, $page->total());
        $this->assertSame([6, 7, 8, 9, 10], $page->getCollection()->modelKeys());
        $this->assertSame(['id', 'title'], array_keys($page->items()[0]->getAttributes()));

        $select = collect(DB::getQueryLog())->first(
            fn (array $query): bool => str_contains($query['query'], 'order by ("searchable_models"."embedding" <=>')
        );
        $plan = DB::select('EXPLAIN (FORMAT JSON) ' . $select['query'], $select['bindings']);
        $this->assertStringContainsString('searchable_models_embedding_vectorindex', json_encode($plan));

        $lastPage = (clone $query)->semantic()->simplePaginate(5, page: 3);
        $this->assertSame([11, 12], $lastPage->getCollection()->modelKeys());
        $this->assertFalse($lastPage->hasMorePages());

        $hybrid = (clone $query)->hybrid()->get();
        $this->assertSame(range(1, 12), $hybrid->modelKeys());
        $this->assertSame(['id', 'title'], array_keys($hybrid->first()->getAttributes()));
    }

    public function testSearchableWritesGeneratedVectors(): void
    {
        Embeddings::fake([[[1, 0, 0]]])->preventStrayEmbeddings();

        $generated = VectorSearchModel::create(['title' => 'Generated']);
        $this->assertSame([1.0, 0.0, 0.0], $generated->fresh()->embedding);
    }
}

class VectorSearchModel extends SearchableModel
{
    protected array $casts = ['embedding' => AsVector::class];

    /**
     * Get the source text or precomputed embedding.
     */
    public function toSearchableEmbedding(): string|array
    {
        return $this->embedding ?? $this->title;
    }
}
