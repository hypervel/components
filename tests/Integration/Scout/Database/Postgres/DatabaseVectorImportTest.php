<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Database\Postgres;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Scout\Scout;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Scout\Searchable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

#[RequiresDatabase('pgsql')]
class DatabaseVectorImportTest extends TestCase
{
    /**
     * Register the database search engine.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [ScoutServiceProvider::class];
    }

    /**
     * Give the import query a separate pooled connection from the model default.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('scout.driver', 'database');
        $config->set('scout.command_concurrency', 50);
        $config->set('database.connections.vector_import', array_replace(
            $config->array('database.connections.' . $config->string('database.default')),
            ['pool' => ['testing_enabled' => true, 'max_connections' => 3, 'wait_timeout' => 0.2]],
        ));
    }

    #[DataProvider('poolCapacities')]
    public function testImportUsesTheQueryConnectionCapacity(int $capacity, bool $wrapResolver = false): void
    {
        $this->ensureParallelDatabaseExists();
        config(['database.connections.vector_import.pool.max_connections' => $capacity]);
        $schema = Schema::connection('vector_import');
        if (! DB::connection('vector_import')->table('pg_available_extensions')->where('name', 'vector')->exists()) {
            $this->markTestSkipped('Requires pgvector.');
        }

        $schema->ensureVectorExtensionExists();
        $schema->create('scout_vector_imports', static function (Blueprint $table): void {
            $table->id();
            $table->vector('embedding', dimensions: 3)->nullable();
        });
        $resolver = Model::getConnectionResolver();

        try {
            if ($wrapResolver) {
                $decorator = m::mock(ConnectionResolverInterface::class);
                $decorator->shouldReceive('connection')->andReturnUsing($resolver->connection(...));
                Model::setConnectionResolver($decorator);
            }

            DB::connection('vector_import')->table('scout_vector_imports')->insert(
                array_map(static fn (int $id): array => ['id' => $id], range(1, 6))
            );
            $parentId = Coroutine::id();

            Scout::whileImporting(static function (): void {
                try {
                    VectorImportModel::makeAllSearchable(1);
                } finally {
                    VectorImportModel::waitForSearchableJobs();
                }
            });

            $this->assertSame(max(1, $capacity - 1), VectorImportModel::$peak);
            $this->assertSame(0, VectorImportModel::$active);
            $this->assertSame(6, VectorImportModel::$dispatches);
            if ($capacity === 1) {
                $this->assertSame([$parentId], array_values(array_unique(VectorImportModel::$coroutineIds)));
            } else {
                $this->assertNotContains($parentId, VectorImportModel::$coroutineIds);
            }
            $this->assertSame(6, DB::connection('vector_import')->table('scout_vector_imports')->whereNotNull('embedding')->count());
            ConnectionResolver::releaseIdleConnections();
            $pool = $this->app->make(PoolManager::class)->existing('vector_import');
            $this->assertSame(0, $pool->getBorrowedCount());
            $this->assertSame(0, $pool->getWaitingCount());
        } finally {
            Model::setConnectionResolver($resolver);
            $schema->dropIfExists('scout_vector_imports');
            VectorImportModel::$active = VectorImportModel::$peak = 0;
            VectorImportModel::$coroutineIds = [];
            VectorImportModel::$dispatches = 0;
        }
    }

    /**
     * Cover child admission, a pool with no spare child slot and a delegating resolver.
     */
    public static function poolCapacities(): array
    {
        return ['concurrent' => [3], 'inline' => [1], 'delegating resolver' => [3, true]];
    }
}

class VectorImportModel extends Model
{
    use Searchable {
        dispatchSearchableJob as protected dispatchImportJob;
    }

    protected ?string $table = 'scout_vector_imports';

    public bool $timestamps = false;

    public static int $active = 0;

    public static int $peak = 0;

    public static array $coroutineIds = [];

    public static int $dispatches = 0;

    /**
     * Record calls through the model's protected dispatch override.
     */
    protected static function dispatchSearchableJob(callable $job): void
    {
        ++static::$dispatches;
        static::dispatchImportJob($job);
    }

    /**
     * Route imported records to the connection chosen by the query.
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return static::on('vector_import');
    }

    /**
     * Read embedding input through the same session used to write the vector.
     */
    public function toSearchableEmbedding(): array
    {
        static::$coroutineIds[] = Coroutine::id();
        $this->getConnection()->selectOne('select 1');
        static::$peak = max(static::$peak, ++static::$active);

        try {
            usleep(10_000);

            return [1.0, 0.0, 0.0];
        } finally {
            --static::$active;
        }
    }
}
