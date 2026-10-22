<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\QueueOutbox\Fixtures;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Coroutine\Waiter;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use Hypervel\Queue\Outbox\OutboxBatchResult;
use Hypervel\Queue\Outbox\OutboxWorker;
use Hypervel\Queue\Outbox\OutboxWorkerOptions;
use Hypervel\Support\Str;
use InvalidArgumentException;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class OutboxWorkload
{
    private string $table;

    private string $jobsTable;

    private array $ownedTables = [];

    private array $template;

    private array $distribution = [];

    private DatabaseOutboxRepository $repository;

    /**
     * Keep feature-specific setup separate from the baseline-compatible runner.
     */
    public function __construct(
        private Application $application,
        private string $source,
        private string $queue,
        string $identifier,
        private array $settings,
    ) {
        $this->table = 'bench_outbox_' . $identifier;
        $this->jobsTable = 'bench_jobs_' . $identifier;
    }

    /**
     * Build isolated tables from the shipped schema and capture one real payload.
     */
    public function setUp(): void
    {
        $connection = $this->application->make('db')->connection($this->source);
        $schema = $connection->getSchemaBuilder();
        $reservation = $this->settings['claim_design'] === 'reservation';

        if ($reservation && $connection->getDriverName() !== 'pgsql') {
            throw new InvalidArgumentException('The reservation comparison is PostgreSQL-only.');
        }

        $tables = [$this->table => 'outbox'];
        if ($this->settings['target'] === 'database') {
            $tables[$this->jobsTable] = 'jobs';
        }

        foreach ($tables as $table => $stub) {
            if ($schema->hasTable($table)) {
                throw new RuntimeException("Benchmark table [{$table}] already exists.");
            }

            $path = tempnam(sys_get_temp_dir(), 'hypervel-outbox-migration-');
            if ($path === false) {
                throw new RuntimeException('Could not create the temporary migration file.');
            }
            $this->ownedTables[] = $table;
            try {
                $contents = file_get_contents(dirname(__DIR__, 4) . "/src/queue/src/Console/stubs/{$stub}.stub");
                file_put_contents($path, str_replace('{{table}}', $table, $contents));
                (require $path)->up();
            } finally {
                unlink($path);
            }
        }

        if ($reservation) {
            $schema->table($this->table, static fn (Blueprint $table) => $table->unsignedBigInteger('reserved_until')->nullable());
            $this->repository = new ReservationRepository($this->application->make('db'), [$this->source], $this->table, 3600);
        } else {
            $this->repository = $this->application->make(DatabaseOutboxRepository::class);
        }

        // Redis staging never contacts Redis. It also avoids the database-queue atomic fast path.
        $this->application->make('config')->set('queue.connections.benchmark_seed', [
            'driver' => 'redis', 'connection' => 'default', 'queue' => $this->queue, 'retry_after' => 3600,
        ]);
        $this->application->make('queue')->connection('benchmark_seed')
            ->push((new OutboxBenchmarkJob(str_repeat('x', $this->settings['payload_bytes'])))->viaOutbox());
        $row = (array) $connection->table($this->table)->useWritePdo()->first();
        unset($row['id']);
        $row['connection'] = 'benchmark';
        if ($reservation) {
            $row['reserved_until'] = null;
        }
        $this->template = $row;
        $connection->table($this->table)->delete();
    }

    /**
     * Seed outside timing; retain claim churn from warmup into the measured sample.
     */
    public function prepare(int $jobs): void
    {
        if ($this->settings['case'] === 'relay') {
            $this->insertRows($jobs);
        } elseif ($this->settings['case'] === 'claims' && $this->distribution === []) {
            $background = intdiv($this->settings['backlog'], 4);
            $leased = $this->settings['leased'];
            $eligible = max($this->settings['backlog'] - 2 * $background - $leased, $this->settings['batch'] * $this->settings['concurrency']);
            $this->distribution = ['eligible' => $eligible, 'delayed' => $background, 'leased' => $leased, 'retrying' => $background];
            $now = time();
            $future = $now + 86400;
            $this->insertRows($eligible);
            $this->insertRows($background, ['available_at' => $future, 'deliver_at' => $future]);
            $this->insertRows($background, ['available_at' => $future, 'attempts' => 5, 'last_error' => 'Benchmark retry backlog']);
            $lease = ['available_at' => $future, 'attempts' => 1, 'lease_token' => (string) Str::uuid()];
            if ($this->settings['claim_design'] === 'reservation') {
                $lease['available_at'] = $now - 60;
                $lease['reserved_until'] = $future;
            }
            $this->insertRows($leased, $lease);
            $this->analyze();
        }
    }

    /**
     * Exercise production staging, the real relay worker, or competing claims.
     */
    public function execute(int $jobs): ?array
    {
        if ($this->settings['case'] === 'stage') {
            $database = $this->application->make('db');
            $queues = $this->application->make('queue');
            for ($offset = 0; $offset < $jobs; $offset += $this->settings['batch']) {
                $this->inFreshCoroutine(function () use ($database, $queues, $jobs, $offset): void {
                    $members = [];
                    for ($index = 0; $index < min($this->settings['batch'], $jobs - $offset); ++$index) {
                        $members[] = (new OutboxBenchmarkJob(str_repeat('x', $this->settings['payload_bytes'])))->viaOutbox();
                    }
                    $database->connection($this->source)->transaction(function () use ($queues, $members): void {
                        if ($this->settings['batch'] === 1) {
                            $queues->connection('benchmark')->push($members[0]);
                        } else {
                            $queues->connection('benchmark')->bulk($members);
                        }
                    });
                });
            }

            return null;
        }

        if ($this->settings['case'] === 'relay') {
            $status = $this->application->make(OutboxWorker::class)->run($this->source, new OutboxWorkerOptions(
                batch: $this->settings['batch'],
                concurrency: $this->settings['concurrency'],
                maxJobs: $jobs,
                maxTime: 300,
                memory: 1024,
                stopWhenEmpty: true,
            ));
            if ($status !== 0) {
                throw new RuntimeException("Relay exited with status {$status}.");
            }

            return null;
        }

        $callbacks = [];
        $clients = min($jobs, $this->settings['concurrency']);
        $deadline = hrtime(true) + 300_000_000_000;
        for ($client = 0; $client < $clients; ++$client) {
            $quota = intdiv($jobs, $clients) + ($client < $jobs % $clients ? 1 : 0);
            $callbacks[] = function () use ($quota, $deadline): array {
                $latencies = [];
                $completed = 0;
                $empty = 0;
                while ($completed < $quota) {
                    if (hrtime(true) >= $deadline) {
                        throw new RuntimeException('The claims workload exceeded its five-minute limit.');
                    }
                    $started = hrtime(true);
                    $messages = $this->inFreshCoroutine(fn (): array => $this->repository->claim($this->source, min($this->settings['batch'], $quota - $completed)));
                    $latencies[] = (hrtime(true) - $started) / 1e6;
                    if ($messages === []) {
                        ++$empty;
                        continue;
                    }
                    $this->inFreshCoroutine(function () use ($messages): void {
                        $settled = $this->repository->settle($this->source, $messages, new OutboxBatchResult(array_column($messages, 'id')));
                        if ($settled !== count($messages)) {
                            throw new RuntimeException('A claimed batch lost ownership during the workload.');
                        }
                        $this->insertRows(count($messages));
                    });
                    $completed += count($messages);
                }

                return ['latencies' => $latencies, 'empty' => $empty];
            };
        }

        $results = parallel($callbacks, $clients);
        $latencies = array_merge(...array_column($results, 'latencies'));
        sort($latencies, SORT_NUMERIC);

        return [
            'claim_operations' => count($latencies),
            'empty_claims' => array_sum(array_column($results, 'empty')),
            'claim_ms_p50' => $latencies[(int) ceil(count($latencies) * 0.5) - 1],
            'claim_ms_p95' => $latencies[(int) ceil(count($latencies) * 0.95) - 1],
            'backlog' => $this->distribution,
        ];
    }

    /**
     * Check completed work rather than treating an early or partial drain as faster.
     */
    public function verify(int $jobs): void
    {
        $query = $this->application->make('db')->connection($this->source)->table($this->table)->useWritePdo();
        $expected = match ($this->settings['case']) {
            'stage' => $jobs,
            'claims' => array_sum($this->distribution),
            default => 0,
        };
        if ($query->count() !== $expected) {
            throw new RuntimeException('The outbox remainder does not match the completed workload.');
        }
        if ($this->settings['case'] === 'claims' && (clone $query)->whereNotNull('lease_token')->count() !== $this->distribution['leased']) {
            throw new RuntimeException('The claims workload left unexpected leases.');
        }
        if ($this->settings['case'] === 'relay' && $this->application->make('queue')->connection('benchmark')->size($this->queue) !== $jobs) {
            throw new RuntimeException('The relay target does not contain every staged job.');
        }
    }

    /**
     * Clear warmup output without removing the mixed claims backlog.
     */
    public function reset(): void
    {
        if ($this->settings['case'] !== 'claims') {
            $this->application->make('db')->connection($this->source)->table($this->table)->delete();
        }
        if ($this->settings['case'] === 'relay') {
            $this->application->make('queue')->connection('benchmark')->clear($this->queue);
        }
    }

    /**
     * Insert bounded batches of realistic opaque envelopes.
     */
    private function insertRows(int $count, array $overrides = []): void
    {
        $connection = $this->application->make('db')->connection($this->source);
        $payload = json_decode($this->template['payload'], true, flags: JSON_THROW_ON_ERROR);
        for ($offset = 0; $offset < $count; $offset += 1000) {
            $rows = [];
            for ($index = 0; $index < min(1000, $count - $offset); ++$index) {
                $uuid = (string) Str::uuid();
                $rows[] = array_replace($this->template, [
                    'uuid' => $uuid, 'payload' => json_encode(array_replace($payload, ['uuid' => $uuid]), JSON_THROW_ON_ERROR),
                    'available_at' => time(), 'created_at' => time(),
                ], $overrides);
            }
            $this->repository->insert($connection, $rows);
        }
    }

    /**
     * End each resource-owning phase before starting unrelated work.
     */
    protected function inFreshCoroutine(Closure $callback): mixed
    {
        return (new Waiter(-1))->wait($callback, waitForChildTermination: true);
    }

    /**
     * Refresh planner statistics without changing vacuum or engine settings.
     */
    private function analyze(): void
    {
        $connection = $this->application->make('db')->connection($this->source);
        $table = $connection->getQueryGrammar()->wrapTable($this->table);
        $connection->statement(in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? "ANALYZE TABLE {$table}" : "ANALYZE {$table}");
    }

    /**
     * Capture actual claim SQL once and inspect executing plans after churn.
     */
    public function explain(): array
    {
        $this->analyze();
        $connection = $this->application->make('db')->connection($this->source);
        $connection->beginTransaction();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        try {
            $this->repository->claim($this->source, $this->settings['batch']);
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
            $connection->rollBack();
        }

        $plans = [];
        foreach ($queries as $query) {
            if (! str_starts_with(strtolower(ltrim($query['query'])), 'with ')
                && ! str_starts_with(strtolower(ltrim($query['query'])), 'select ')) {
                continue;
            }
            $prefix = match ($connection->getDriverName()) {
                'pgsql' => 'EXPLAIN (ANALYZE, BUFFERS, WAL, FORMAT JSON) ',
                'mysql' => 'EXPLAIN ANALYZE ',
                'mariadb' => 'ANALYZE FORMAT=JSON ',
                'sqlite' => 'EXPLAIN QUERY PLAN ',
                default => throw new InvalidArgumentException('No plan command is defined for this database driver.'),
            };
            $connection->beginTransaction();
            try {
                $plans[] = ['sql' => $query['query'], 'plan' => $connection->selectFromWriteConnection($prefix . $query['query'], $query['bindings'])];
            } finally {
                $connection->rollBack();
            }
        }

        return $plans;
    }

    /**
     * Capture the cluster-wide logical WAL position outside the timed workload.
     */
    public function walPosition(): ?string
    {
        $connection = $this->application->make('db')->connection($this->source);

        return $this->settings['case'] === 'claims' && $connection->getDriverName() === 'pgsql'
            ? $connection->selectOne('SELECT pg_current_wal_insert_lsn() AS position', useReadPdo: false)->position
            : null;
    }

    /**
     * Report total workload WAL, including refills, settlement and background activity.
     */
    public function walBytes(?string $start): ?int
    {
        return $start === null ? null : (int) $this->application->make('db')->connection($this->source)
            ->selectOne('SELECT pg_wal_lsn_diff(pg_current_wal_insert_lsn(), ?) AS bytes', [$start], false)->bytes;
    }

    /**
     * Describe the database and actual serialized payload without credentials.
     */
    public function backend(): array
    {
        $connection = $this->application->make('db')->connection($this->source);

        return [
            'driver' => $connection->getDriverName(), 'version' => $connection->getServerVersion(),
            'database' => $connection->getDatabaseName(), 'table' => $this->table,
            'payload_bytes' => strlen($this->template['payload']),
            'functional_only' => $connection->getDriverName() === 'sqlite',
        ];
    }

    /**
     * Remove only the tables this invocation created.
     */
    public function tearDown(): void
    {
        $schema = $this->application->make('db')->connection($this->source)->getSchemaBuilder();
        foreach (array_reverse($this->ownedTables) as $table) {
            $schema->dropIfExists($table);
            if ($schema->hasTable($table)) {
                throw new RuntimeException("Benchmark table [{$table}] was not removed.");
            }
        }
    }
}
