<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\Ai\Fixtures;

use Hypervel\Ai\AiManager;
use Hypervel\Ai\AiServiceProvider;
use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Casts\AsVector;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Events\ConnectionEvent;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Scout\Console\ConcurrentImportRunner;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\DatabaseEngine;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Scout\Searchable;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

use function Hypervel\Coroutine\parallel;

class ScoutVectorBenchmark extends TestCase
{
    private bool $recording = false;

    private array $statements = [];

    private float $updateMilliseconds = 0;

    /**
     * Release fixture vectors even when an import fails.
     */
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            ScoutBenchmarkModel::$precomputed = [];
        }
    }

    /**
     * Require an explicit run against a disposable local PostgreSQL database.
     */
    protected function setUp(): void
    {
        $this->assertTrue(getenv('BENCH_SMOKE') === '1' || getenv('BENCH_IDLE_CONFIRMED') === '1');
        $this->assertNotFalse(filter_var(getenv('BENCH_DB_PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]));
        $this->assertContains(getenv('BENCH_SCOUT_VARIANT'), ['current', 'batch-transaction']);
        if (($concurrency = getenv('BENCH_SCOUT_CONCURRENCY')) !== false) {
            $this->assertNotFalse(filter_var($concurrency, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]));
        }
        parent::setUp();

        app(Dispatcher::class)->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if (! $this->recording || $event->connectionName !== 'benchmark') {
                return;
            }

            $type = strtolower(strtok(ltrim($event->sql), ' '));
            $this->statements[$type] = ($this->statements[$type] ?? 0) + 1;

            if ($type === 'update') {
                $this->updateMilliseconds += $event->time;

                if ($event->connection->transactionLevel() === 0) {
                    $this->statements['autocommit_writes'] = ($this->statements['autocommit_writes'] ?? 0) + 1;
                }
            }
        });

        foreach ([TransactionBeginning::class => 'begin', TransactionCommitted::class => 'commit', TransactionRolledBack::class => 'rollback'] as $event => $type) {
            app(Dispatcher::class)->listen($event, function (ConnectionEvent $event) use ($type): void {
                if ($this->recording && $event->connectionName === 'benchmark') {
                    $this->statements[$type] = ($this->statements[$type] ?? 0) + 1;
                }
            });
        }
    }

    /**
     * Register the real Scout import command and its optional AI provider.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [ScoutServiceProvider::class, AiServiceProvider::class];
    }

    /**
     * Separate the writer's pool from the import worker's pool.
     */
    protected function defineEnvironment(Application $app): void
    {
        $connection = [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => (int) getenv('BENCH_DB_PORT'),
            'database' => 'ai_benchmark', 'username' => 'postgres', 'password' => 'benchmark',
            'charset' => 'utf8', 'prefix' => '',
            'pool' => ['testing_enabled' => true, 'min_retained_connections' => 0, 'heartbeat_interval' => null, 'idle_check_interval' => null],
        ];
        $app->make('config')->set([
            'database.default' => 'benchmark',
            'database.connections' => ['benchmark' => $connection, 'writer' => $connection],
            'scout.driver' => 'benchmark',
            'scout.queue.enabled' => false,
            'scout.chunk.searchable' => 500,
            'ai.providers.openai' => ['driver' => 'openai', 'key' => 'benchmark-key'],
            'ai.caching.embeddings.store' => 'array',
        ]);

        if (($concurrency = getenv('BENCH_SCOUT_CONCURRENCY')) !== false) {
            $app->make('config')->set('scout.command_concurrency', (int) $concurrency);
        }
    }

    #[DataProvider('inputModes')]
    public function testImportWithConcurrentWriter(bool $generated): void
    {
        $smoke = getenv('BENCH_SMOKE') === '1';
        $probe = getenv('BENCH_PROBE') === '1';
        $targets = $smoke ? 1000 : 5000;
        $background = $smoke ? 100 : 1000;
        config(['benchmark.scout_targets' => $targets]);
        $vectors = $this->vectors($targets + $background);
        ScoutBenchmarkModel::$precomputed = $generated ? [] : $vectors;
        $engine = new ScoutBenchmarkEngine;
        app(EngineManager::class)->extend('benchmark', fn (): DatabaseEngine => $engine);
        $gateway = new ScoutBenchmarkEmbeddingGateway($vectors);
        app(AiManager::class)->embeddingProvider('openai')->useEmbeddingGateway($gateway);

        for ($sample = $probe ? 0 : -1; $sample < ($smoke || $probe ? 1 : 3); ++$sample) {
            $this->prepare($vectors, $targets);
            Cache::store('array')->clear();
            $gateway->calls = 0;
            $engine->peak = 0;
            $beforeDatabase = $this->databaseState();
            ConnectionResolver::releaseIdleConnections();
            $this->statements = [];
            $this->updateMilliseconds = 0;
            $importFinishedAt = null;
            $writerLatencies = [];
            $writerServiceTimes = [];
            $writerCheckouts = [];
            $writerAdmissions = [];
            $writerInterval = 20_000_000;
            $writerLimit = app(PoolManager::class)->pool('writer')->getOptions()->maxConnections;
            $activeWriters = 0;
            $peakWriters = 0;
            $writerCompletedDuringImport = 0;
            $writerFinishedAt = 0;
            $overlappingWrites = 0;
            $importMilliseconds = 0;
            $importPool = app(PoolManager::class)->pool('benchmark');
            $peakBorrowed = 0;
            $peakWaiting = 0;
            gc_collect_cycles();
            memory_reset_peak_usage();
            $cpuBefore = self::cpuMilliseconds();
            $started = hrtime(true);
            $this->recording = true;

            try {
                $results = parallel([
                    function () use (&$importFinishedAt, &$importMilliseconds): int {
                        $started = hrtime(true);

                        try {
                            return app(Kernel::class)->call('scout:import', ['model' => ScoutBenchmarkModel::class], new NullOutput);
                        } finally {
                            $importFinishedAt = hrtime(true);
                            $importMilliseconds = ($importFinishedAt - $started) / 1e6;
                        }
                    },
                    function () use ($targets, $engine, $importPool, $started, $writerInterval, $writerLimit, &$importFinishedAt, &$writerLatencies, &$writerServiceTimes, &$writerCheckouts, &$writerAdmissions, &$activeWriters, &$peakWriters, &$writerCompletedDuringImport, &$writerFinishedAt, &$overlappingWrites, &$peakBorrowed, &$peakWaiting): void {
                        $random = new Randomizer(new Mt19937(4321));
                        // Reuse the bounded runner's failure propagation and child-cancellation handling.
                        $writers = new ConcurrentImportRunner($writerLimit);

                        try {
                            // Keep arrivals independent of blocked writes, including work awaiting admission at import end.
                            for ($operation = 0;; ++$operation) {
                                $scheduled = $started + $operation * $writerInterval;

                                if ($importFinishedAt !== null && $scheduled >= $importFinishedAt) {
                                    break;
                                }

                                while (($delay = $scheduled - hrtime(true)) > 0) {
                                    usleep((int) ceil($delay / 1000));
                                }

                                if ($importFinishedAt !== null && $scheduled >= $importFinishedAt) {
                                    break;
                                }

                                $key = $random->getInt(1, $targets);
                                $admissionStarted = hrtime(true);
                                $writers->create(function () use ($key, $scheduled, $admissionStarted, $engine, $importPool, &$importFinishedAt, &$writerLatencies, &$writerServiceTimes, &$writerCheckouts, &$writerAdmissions, &$activeWriters, &$peakWriters, &$writerCompletedDuringImport, &$writerFinishedAt, &$overlappingWrites, &$peakBorrowed, &$peakWaiting): void {
                                    $writeStarted = hrtime(true);
                                    $writerAdmissions[] = ($writeStarted - $admissionStarted) / 1e6;
                                    $peakWriters = max($peakWriters, ++$activeWriters);

                                    try {
                                        $peakBorrowed = max($peakBorrowed, $importPool->getBorrowedCount());
                                        $peakWaiting = max($peakWaiting, $importPool->getWaitingCount());
                                        $connection = DB::connection('writer');
                                        $connection->getPdo();
                                        $writerCheckouts[] = (hrtime(true) - $writeStarted) / 1e6;
                                        $overlappingWrites += (int) ($engine->active > 0);
                                        $connection->table('benchmark_scout_vectors')->where('id', $key)->increment('revision');
                                        $writerFinishedAt = hrtime(true);
                                        $writerLatencies[] = ($writerFinishedAt - $scheduled) / 1e6;
                                        $writerServiceTimes[] = ($writerFinishedAt - $writeStarted) / 1e6;
                                        $writerCompletedDuringImport += (int) ($importFinishedAt === null || $writerFinishedAt <= $importFinishedAt);
                                    } finally {
                                        try {
                                            // Return the session before body completion admits another writer.
                                            ConnectionResolver::releaseIdleConnections();
                                        } finally {
                                            --$activeWriters;
                                        }
                                    }
                                });
                            }
                        } finally {
                            $writers->wait();
                        }
                    },
                ], 2);
            } catch (Throwable $exception) {
                fwrite(STDERR, json_encode(['case' => $generated ? 'generated' : 'precomputed', 'sample' => $sample, 'sampled_peak_borrowed' => $peakBorrowed, 'sampled_peak_waiting' => $peakWaiting, 'statements' => $this->statements], JSON_THROW_ON_ERROR) . PHP_EOL);

                throw $exception;
            } finally {
                $wall = (hrtime(true) - $started) / 1e6;
                $cpu = self::cpuMilliseconds() - $cpuBefore;
                $peakHeap = memory_get_peak_usage();
                $this->recording = false;
            }

            $this->assertSame(0, $results[0], "Sampled import pool peaks: {$peakBorrowed} borrowed, {$peakWaiting} waiting.");

            foreach (['benchmark', 'writer'] as $name) {
                $pool = app(PoolManager::class)->pool($name);
                $this->assertSame(0, $pool->getBorrowedCount());
                $this->assertSame(0, $pool->getWaitingCount());
            }

            $afterDatabase = $this->databaseState();
            $walBytes = (int) DB::scalar('SELECT pg_wal_lsn_diff(?, ?)::bigint', [$afterDatabase['lsn'], $beforeDatabase['lsn']]);
            $this->assertSame($targets, $this->statements['update']);
            $this->assertSame($this->batched() ? 0 : $targets, $this->statements['autocommit_writes'] ?? 0);
            $this->assertSame($this->batched() ? intdiv($targets, 100) : 0, $this->statements['commit'] ?? 0);
            $this->assertSame($generated ? intdiv($targets, 100) : 0, $gateway->calls);
            $this->assertSame(0, $engine->active);
            $this->assertGreaterThan(1, $engine->peak);
            $this->assertGreaterThan(0, $overlappingWrites);
            $this->assertSame((int) ceil(($importFinishedAt - $started) / $writerInterval), count($writerLatencies));
            $this->assertSame(count($writerLatencies), (int) DB::table('benchmark_scout_vectors')->sum('revision'));
            $this->assertSame($targets + $background, DB::table('benchmark_scout_vectors')->whereNotNull('embedding')->count());
            $this->assertEqualsWithDelta($vectors['Document 1'], ScoutBenchmarkModel::findOrFail(1)->embedding, 0.000001);
            $this->checkSpace();

            if ($sample >= 0) {
                file_put_contents((string) getenv('BENCH_OUT'), json_encode([
                    'case' => $generated ? 'generated' : 'precomputed', 'sample' => $sample, 'smoke' => $smoke, 'probe' => $probe,
                    'variant' => getenv('BENCH_SCOUT_VARIANT'), 'targets' => $targets, 'background' => $background,
                    'combined_client_cpu_ms' => $cpu, 'wall_ms' => $wall, 'import_ms' => $importMilliseconds,
                    'models_per_second' => $targets * 1000 / $importMilliseconds, 'peak_import_jobs' => $engine->peak,
                    'sampled_peak_borrowed' => $peakBorrowed, 'sampled_peak_waiting' => $peakWaiting,
                    'command_concurrency' => config()->integer('scout.command_concurrency'),
                    'writer_operations' => count($writerLatencies), 'overlapping_writer_operations' => $overlappingWrites,
                    'writer_interval_ms' => $writerInterval / 1e6,
                    'writer_concurrency' => $writerLimit, 'peak_writer_jobs' => $peakWriters,
                    'writer_completed_during_import' => $writerCompletedDuringImport,
                    'writer_drain_ms' => max(0, $writerFinishedAt - $importFinishedAt) / 1e6,
                    'writer_operations_per_second' => count($writerLatencies) * 1000 / $wall,
                    'writer_ms' => self::latencies($writerLatencies), 'writer_service_ms' => self::latencies($writerServiceTimes),
                    'writer_admission_ms' => self::latencies($writerAdmissions),
                    'writer_checkout_ms' => self::latencies($writerCheckouts),
                    'statements' => $this->statements, 'inclusive_update_ms' => $this->updateMilliseconds,
                    'database_before' => $beforeDatabase, 'database_after' => $afterDatabase, 'wal_bytes' => $walBytes,
                    'peak_heap' => $peakHeap,
                ], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND);
            }
        }
    }

    /**
     * Exercise both the SDK generation path and precomputed model vectors.
     */
    public static function inputModes(): array
    {
        return ['generated' => [true], 'precomputed' => [false]];
    }

    public function testRollbackAndExistingTransactionBoundaries(): void
    {
        $vectors = $this->vectors(2);
        $this->prepare($vectors, 2);
        $models = ScoutBenchmarkModel::orderBy('id')->get();
        $models[0]->embedding = $vectors['Document 1'];
        $models[1]->embedding = [1, 2];

        try {
            (new DatabaseEngine)->update($models);
            $this->fail('A vector with the wrong dimensions must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('expected 1536 dimensions', $exception->getMessage());
        }

        $this->assertSame($this->batched() ? 0 : 1, DB::table('benchmark_scout_vectors')->whereNotNull('embedding')->count());
        $models[1]->embedding = $vectors['Document 2'];
        $this->statements = [];
        $this->recording = true;

        try {
            (new DatabaseEngine)->update($models->take(1));
            $this->assertSame(0, $this->statements['begin'] ?? 0);
            DB::beginTransaction();
            (new DatabaseEngine)->update($models);
            DB::rollBack();
        } finally {
            $this->recording = false;
        }

        $this->assertSame(1, $this->statements['begin']);
        $this->assertSame(1, $this->statements['rollback']);
        $this->assertSame(0, $this->statements['commit'] ?? 0);
    }

    /**
     * Reset heap/index state and flush setup writes before each measured sample.
     */
    private function prepare(array $vectors, int $targets): void
    {
        $this->checkSpace();
        ConnectionResolver::releaseIdleConnections();
        Schema::ensureVectorExtensionExists();
        Schema::dropIfExists('benchmark_scout_vectors');
        Schema::create('benchmark_scout_vectors', function (Blueprint $table): void {
            $table->bigInteger('id')->primary();
            $table->string('title');
            $table->integer('revision')->default(0);
            $table->vector('embedding', dimensions: 1536)->nullable();
        });
        DB::statement('ALTER TABLE benchmark_scout_vectors SET (autovacuum_enabled = false, toast.autovacuum_enabled = false)');
        $rows = [];
        $id = 0;

        foreach ($vectors as $title => $vector) {
            ++$id;
            $rows[] = ['id' => $id, 'title' => $title, 'embedding' => $id <= $targets ? null : json_encode($vector, JSON_THROW_ON_ERROR)];

            if (count($rows) === 100) {
                DB::table('benchmark_scout_vectors')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('benchmark_scout_vectors')->insert($rows);
        }

        Schema::table('benchmark_scout_vectors', fn (Blueprint $table) => $table->vectorIndex('embedding'));
        DB::statement('ANALYZE benchmark_scout_vectors');
        DB::statement('CHECKPOINT');
        $this->checkSpace();
    }

    /**
     * Read physical sizes, WAL position and completed checkpoint counters outside timing.
     */
    private function databaseState(): array
    {
        DB::select('SELECT pg_stat_clear_snapshot()');

        return (array) DB::selectOne("SELECT pg_current_wal_lsn()::text AS lsn, pg_table_size('benchmark_scout_vectors') AS table_bytes, pg_indexes_size('benchmark_scout_vectors') AS index_bytes, num_timed, num_requested FROM pg_stat_checkpointer");
    }

    /**
     * Keep deterministic, distinct vector generation outside the measured path.
     */
    private function vectors(int $count): array
    {
        $random = new Randomizer(new Mt19937(1234));
        $vectors = [];

        for ($id = 1; $id <= $count; ++$id) {
            $vector = [];

            for ($dimension = 0; $dimension < 1536; ++$dimension) {
                $vector[] = $random->getInt(-5000000, 5000000) / 100000000;
            }

            $vectors['Document ' . $id] = $vector;
        }

        return $vectors;
    }

    /**
     * Stop before another sample if the host's Docker storage reserve is exhausted.
     */
    private function checkSpace(): void
    {
        $this->assertGreaterThan(2 * 1024 ** 3, disk_free_space('/var/lib/docker'), 'The disposable Docker data volume needs at least 2 GiB free.');
    }

    /**
     * Select the expected behavior of the externally patched checkout.
     */
    private function batched(): bool
    {
        return getenv('BENCH_SCOUT_VARIANT') === 'batch-transaction';
    }

    /**
     * Summarize writer timings without using performance thresholds as assertions.
     */
    private static function latencies(array $values): array
    {
        sort($values, SORT_NUMERIC);

        return ['p50' => $values[(int) ceil(count($values) * 0.5) - 1], 'p95' => $values[(int) ceil(count($values) * 0.95) - 1], 'p99' => $values[(int) ceil(count($values) * 0.99) - 1], 'max' => end($values)];
    }

    /**
     * Return user and system CPU for the entire client process.
     */
    private static function cpuMilliseconds(): float
    {
        $usage = getrusage();

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000 + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000;
    }
}

class ScoutBenchmarkModel extends Model
{
    use Searchable;

    /** @var array<string, list<float>> */
    public static array $precomputed = [];

    public bool $timestamps = false;

    protected ?string $table = 'benchmark_scout_vectors';

    protected array $guarded = [];

    protected array $casts = ['embedding' => AsVector::class];

    /**
     * Import target rows while leaving the background graph in place.
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->where('id', '<=', config()->integer('benchmark.scout_targets'));
    }

    /**
     * Use either an existing model vector or text for local generation.
     */
    public function toSearchableEmbedding(): string|array
    {
        return $this->embedding ?? static::$precomputed[$this->title] ?? $this->title;
    }
}

class ScoutBenchmarkEngine extends DatabaseEngine
{
    public int $active = 0;

    public int $peak = 0;

    /**
     * Observe live import jobs without replacing the production write algorithm.
     */
    public function update(Collection $models): void
    {
        $this->peak = max($this->peak, ++$this->active);

        try {
            parent::update($models);
        } finally {
            --$this->active;
        }
    }
}

class ScoutBenchmarkEmbeddingGateway implements EmbeddingGateway
{
    public int $calls = 0;

    /**
     * Retain deterministic vectors prepared before measurement.
     */
    public function __construct(private array $vectors)
    {
    }

    /**
     * Simulate generation latency through the real SDK without external services.
     */
    public function generateEmbeddings(EmbeddingProvider $provider, string $model, array $inputs, int $dimensions, int $timeout = 30, array $providerOptions = []): EmbeddingsResponse
    {
        ++$this->calls;
        // Match the framework HTTP client's release boundary before external I/O.
        ConnectionResolver::releaseIdleConnections();
        usleep(20000);

        return new EmbeddingsResponse(array_map(fn (string $input): array => $this->vectors[$input], $inputs), new Usage(count($inputs)), new Meta($provider->name(), $model));
    }
}
