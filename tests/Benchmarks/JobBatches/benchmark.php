<?php

declare(strict_types=1);

use Hypervel\Bus\Batch;
use Hypervel\Bus\BatchFactory;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\MariaDbConnection;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\CarbonImmutable;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/Repositories.php';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

class Metrics
{
    public static int $statements = 0;

    public static int $transactionTime = 0;

    public static int $lockedTime = 0;

    public static int $transactions = 0;

    public static int $began = 0;

    public static int $locked = 0;

    public static int $callbacks = 0;

    public static int $idReads = 0;
}

class TimedStatement extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        ++Metrics::$statements;
        $result = parent::execute($params);
        if (Metrics::$began && ! Metrics::$locked && stripos($this->queryString, 'job_batches') !== false
            && (stripos($this->queryString, 'for update') !== false || preg_match('/^\s*(update|with ins)/i', $this->queryString))) {
            Metrics::$locked = hrtime(true);
        }
        return $result;
    }
}

class TimedPdo extends PDO
{
    public function beginTransaction(): bool
    {
        Metrics::$began = hrtime(true);
        Metrics::$locked = 0;
        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        $result = parent::commit();
        $now = hrtime(true);
        Metrics::$transactionTime += Metrics::$began ? $now - Metrics::$began : 0;
        Metrics::$lockedTime += Metrics::$locked ? $now - Metrics::$locked : 0;
        ++Metrics::$transactions;
        Metrics::$began = Metrics::$locked = 0;
        return $result;
    }

    public function rollBack(): bool
    {
        Metrics::$began = Metrics::$locked = 0;
        return parent::rollBack();
    }
}

class BatchProgress
{
    public function __construct(public bool $readIds)
    {
    }

    public function __invoke(Batch $batch): void
    {
        ++Metrics::$callbacks;
        $batch->progress();
        if ($this->readIds) {
            Metrics::$idReads += count($batch->failedJobIds);
        }
    }
}

function openPdo(bool $timed = false): PDO
{
    $class = $timed ? TimedPdo::class : PDO::class;
    $pdo = new $class(getenv('BENCH_DSN'), getenv('BENCH_USER') ?: null, getenv('BENCH_PASSWORD') ?: null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    if ($timed) {
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TimedStatement::class]);
    }
    return $pdo;
}

class OutcomeFactory extends BatchFactory
{
    public function make(
        BatchRepository $repository,
        string $id,
        string $name,
        int $totalJobs,
        ?int $pendingJobs,
        ?int $failedJobs,
        ?array $failedJobIds,
        array $options,
        CarbonImmutable $createdAt,
        ?CarbonImmutable $cancelledAt,
        ?CarbonImmutable $finishedAt
    ): Batch {
        return new OutcomeBatch(
            $this->queue,
            $repository,
            $id,
            $name,
            $totalJobs,
            $pendingJobs,
            $failedJobs,
            $failedJobIds,
            $options,
            $createdAt,
            $cancelledAt,
            $finishedAt
        );
    }
}

function repository(string $engine, string $variant): DatabaseBatchRepository
{
    $class = ['mysql' => MySqlConnection::class, 'mariadb' => MariaDbConnection::class, 'pgsql' => PostgresConnection::class, 'sqlite' => SQLiteConnection::class][$engine];
    $db = new $class(openPdo(true), 'batchbench', '', ['driver' => $engine, 'name' => 'benchmark']);
    $resolver = new class($db) implements ConnectionResolverInterface {
        public function __construct(private Connection $db)
        {
        }

        public function connection(UnitEnum|string|null $name = null): Connection
        {
            return $this->db;
        }

        public function getDefaultConnection(): ?string
        {
            return 'benchmark';
        }

        public function setDefaultConnection(?string $name): void
        {
        }
    };
    $queue = new class implements Factory {
        public function connection(UnitEnum|string|null $name = null): Queue
        {
            throw new LogicException('No queue transport in benchmark');
        }
    };

    if ($variant === 'A') {
        return new DatabaseBatchRepository(new BatchFactory($queue), $resolver, 'job_batches');
    }
    $outcomes = str_starts_with($variant, 'C');
    $repo = new CountingPrototype($outcomes ? new OutcomeFactory($queue) : new BatchFactory($queue), $resolver, 'job_batches');
    $repo->outcomes = $outcomes;
    $repo->combined = str_contains($variant, 'cte');
    $repo->captureBatch = str_contains($variant, 'reuse');
    return $repo;
}

function jobId(int $id): string
{
    return sprintf('b22dbb70-36e8-4918-8500-%012d', $id);
}

function batchId(int $worker): string
{
    return sprintf('c22dbb70-36e8-4918-8500-%012d', $worker);
}

function setup(PDO $pdo, string $engine, string $variant, string $case, int $jobs, int $workers): void
{
    // run.py provisions a private, disposable server for this harness.
    $pdo->exec('drop table if exists job_outcomes');
    $pdo->exec('drop table if exists job_batches');
    $outcomes = str_starts_with($variant, 'C');
    $mysql = in_array($engine, ['mysql', 'mariadb'], true);
    $columns = $outcomes ? 'outstanding integer not null' : 'failed_job_ids json not null';
    $text = $mysql ? 'mediumtext' : 'text';
    $pdo->exec("create table job_batches (id varchar(255) primary key, name varchar(255) not null,
        total_jobs int not null, pending_jobs int not null, failed_jobs int not null, {$columns},
        options {$text}, cancelled_at int, created_at int not null, finished_at int)");
    if ($variant !== 'A') {
        $pdo->exec('create index prune_batches on job_batches(finished_at,created_at)');
        $extra = $outcomes ? ', retry_id text' : '';
        $pdo->exec("create table job_outcomes(batch_id varchar(255) not null, job_id_hash char(32) not null {$extra},
            primary key(batch_id,job_id_hash))");
        if ($outcomes && $variant !== 'C-noindex') {
            $pdo->exec($mysql ? 'create index failures on job_outcomes(batch_id,retry_id(1))'
                : 'create index failures on job_outcomes(batch_id) where retry_id is not null');
        }
    }

    $failures = in_array($case, ['heavy', 'progress', 'readids'], true) ? 5000 : 0;
    $ids = $failures ? array_map('jobId', range(1, $failures)) : [];
    $options = ['allowFailures' => $case !== 'check'];
    if ($case === 'progress' || $case === 'readids') {
        $options['progress'] = [new BatchProgress($case === 'readids')];
    }
    $encodedOptions = serialize($options);
    if ($engine === 'pgsql') {
        $encodedOptions = base64_encode($encodedOptions);
    }
    $parents = $case === 'independent' ? $workers : 1;
    $total = intdiv($jobs, $parents) + $failures;
    $statement = $pdo->prepare('insert into job_batches values (?,?,?,?,?,?,?,null,?,null)');
    for ($i = 0; $i < $parents; ++$i) {
        $statement->execute([batchId($i), 'benchmark', $total, $total, $failures,
            $outcomes ? $failures : json_encode($ids, JSON_THROW_ON_ERROR), $encodedOptions, time()]);
    }
    if ($outcomes && $ids) {
        foreach (array_chunk($ids, 500) as $chunk) {
            $values = [];
            foreach ($chunk as $id) {
                array_push($values, batchId(0), hash('xxh128', $id), $id);
            }
            $pdo->prepare('insert into job_outcomes values ' . implode(',', array_fill(0, count($chunk), '(?,?,?)')))->execute($values);
        }
    }
}

function verifyImplementation(string $engine, string $variant): void
{
    $pdo = openPdo();
    setup($pdo, $engine, $variant, 'check', 2, 1);
    $repo = repository($engine, $variant);
    $batch = batchId(0);
    if ($variant === 'A') {
        $repo->find($batch)->recordSuccessfulJob('a');
        $repo->find($batch)->recordSuccessfulJob('a');
        check($repo->find($batch)->finished(), 'Expected baseline defect');
        return;
    }
    $repo->incrementFailedJobs($batch, 'position', '0');
    $repeat = $repo->incrementFailedJobs($batch, 'position', 'different-lookup');
    check($repeat->failedJobs === 2 && ! $repeat->completedFirstPass, 'Repeated failure');
    check($repo->find($batch)->failedJobIds === ['0'], 'Original retry identifier');
    $counts = $repo->decrementPendingJobs($batch, 'other');
    check($counts->pendingJobs === 1 && $counts->completedFirstPass && ! $repo->find($batch)->finished(), 'First pass');
    check($repo->decrementPendingJobs($batch, 'other') === null, 'Duplicate');
    check($repo->incrementFailedJobs($batch, 'other') === null, 'Stale failure');
    $counts = $repo->decrementPendingJobs($batch, 'position');
    $view = $repo->find($batch);
    check(! $counts->completedFirstPass && $counts->pendingJobs === 0 && $view->finished() && $view->failedJobIds === [], 'Recovery finish');
    check($repo->decrementPendingJobs('absent', 'missing') === null, 'Missing parent');
    check((int) $pdo->query("select count(*) from job_outcomes where batch_id='absent'")->fetchColumn() === 0, 'Orphan');

    setup($pdo, $engine, $variant, 'heavy', 1, 1);
    $repo->decrementPendingJobs($batch, 'last-fresh');
    check($repo->find($batch)->finished(), 'Failed-only finish');
    $pdo->exec('update job_batches set finished_at=100,cancelled_at=100');
    $repo->decrementPendingJobs($batch, jobId(1));
    check($repo->find($batch)->finishedAt->getTimestamp() === 100, 'Cancellation finish time');
    try {
        $repo->getConnection()->transaction(function () use ($repo, $batch) {
            $repo->decrementPendingJobs($batch, jobId(2));
            throw new DomainException('rollback');
        });
    } catch (DomainException) {
    }
    check($repo->decrementPendingJobs($batch, jobId(2)) !== null, 'Rollback');
}

function logPosition(PDO $pdo, string $engine): int
{
    if ($engine === 'pgsql') {
        return (int) $pdo->query("select pg_wal_lsn_diff(pg_current_wal_insert_lsn(),'0/0')")->fetchColumn();
    }
    if ($engine === 'mysql') {
        return array_sum(array_column($pdo->query('show binary logs')->fetchAll(PDO::FETCH_ASSOC), 'File_size'));
    }
    return 0;
}

function runSample(string $engine, string $variant, string $case, int $jobs, int $workers): array
{
    check($workers > 0 && $jobs % $workers === 0, 'Even work per worker');
    $pdo = openPdo();
    setup($pdo, $engine, $variant, $case, $jobs, $workers);
    $pdo = null;
    $sockets = [];
    $pids = [];
    for ($worker = 0; $worker < $workers; ++$worker) {
        [$parent,$child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        check($pid >= 0, 'fork failed');
        if ($pid === 0) {
            fclose($parent);
            try {
                $repo = repository($engine, $variant);
                $batch = batchId($case === 'independent' ? $worker : 0);
                $warm = $repo->find($batch);
                check($warm !== null, 'Warm connection');
                Metrics::$statements = 0;
                fwrite($child, "ready\n");
                check(fgets($child) === "go\n", 'Start barrier');
                for ($i = $worker; $i < $jobs; $i += $workers) {
                    $job = jobId(100000 + $i);
                    if (str_contains($variant, 'reuse')) {
                        $repo->recordWithoutLookup($batch, $job);
                    } else {
                        $repo->find($batch)->recordSuccessfulJob($job);
                    }
                }
                fwrite($child, json_encode([hrtime(true), Metrics::$statements, Metrics::$transactionTime,
                    Metrics::$lockedTime, Metrics::$transactions, Metrics::$callbacks, Metrics::$idReads], JSON_THROW_ON_ERROR) . "\n");
                exit(0);
            } catch (Throwable $e) {
                fwrite(STDERR, (string) $e . "\n");
                exit(1);
            }
        }
        fclose($child);
        $sockets[] = $parent;
        $pids[] = $pid;
    }

    foreach ($sockets as $socket) {
        check(fgets($socket) === "ready\n", 'Worker failed before start');
    }
    $pdo = openPdo();
    $before = logPosition($pdo, $engine);
    $start = hrtime(true);
    foreach ($sockets as $socket) {
        fwrite($socket, "go\n");
    }
    $sums = array_fill(0, 7, 0);
    $end = $start;
    foreach ($sockets as $socket) {
        $stats = json_decode((string) fgets($socket), true, flags: JSON_THROW_ON_ERROR);
        $end = max($end, $stats[0]);
        foreach (array_slice($stats, 1, null, true) as $key => $value) {
            $sums[$key] += $value;
        }
        fclose($socket);
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Worker exit');
    }
    $bytes = logPosition($pdo, $engine) - $before;
    $expectedFailures = in_array($case, ['heavy', 'progress', 'readids'], true) ? 5000 : 0;
    $final = $pdo->query('select * from job_batches')->fetchAll(PDO::FETCH_OBJ);
    foreach ($final as $row) {
        check((int) $row->pending_jobs === $expectedFailures && (int) $row->failed_jobs === $expectedFailures && $row->finished_at !== null, 'Final counts');
        if (isset($row->outstanding)) {
            check((int) $row->outstanding === $expectedFailures, 'Final outstanding failures');
        } else {
            check(count(json_decode($row->failed_job_ids, true)) === $expectedFailures, 'Final JSON cardinality');
        }
    }
    if ($variant !== 'A') {
        $success = str_starts_with($variant, 'C') ? ' where retry_id is null' : '';
        check((int) $pdo->query("select count(*) from job_outcomes{$success}")->fetchColumn() === $jobs, 'Success cardinality');
    }
    $hasCallback = in_array($case, ['progress', 'readids'], true);
    check($sums[5] === ($hasCallback ? $jobs : 0) && $sums[6] === ($case === 'readids' ? $jobs * 5000 : 0), 'Callback use');
    return ['engine' => $engine, 'variant' => $variant, 'case' => $case, 'jobs' => $jobs, 'workers' => $workers,
        'seconds' => ($end - $start) / 1e9, 'sql_per_job' => $sums[1] / $jobs, 'tx_ms_per_job' => $sums[2] / $jobs / 1e6,
        'parent_lock_lower_bound_ms_per_job' => $sums[3] / $jobs / 1e6, 'log_bytes_per_job' => $bytes / $jobs];
}

[$script,$mode,$engine,$variant] = $argv;
if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) {
    return;
}
if ($mode === 'check') {
    verifyImplementation($engine, $variant);
} elseif ($mode === 'sample') {
    echo json_encode(runSample($engine, $variant, $argv[4], (int) $argv[5], (int) $argv[6]), JSON_THROW_ON_ERROR), "\n";
} elseif ($mode === 'settings') {
    $pdo = openPdo();
    if ($engine === 'pgsql') {
        $config = $pdo->query("select name,setting from pg_settings where name in ('server_version','fsync','synchronous_commit','full_page_writes','transaction_isolation')")->fetchAll(PDO::FETCH_KEY_PAIR);
    } else {
        $config = $pdo->query("show variables where variable_name in ('version','innodb_flush_log_at_trx_commit','sync_binlog','log_bin','binlog_format','binlog_row_image','transaction_isolation','tx_isolation')")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    setup($pdo, $engine, $variant, 'heavy', 1, 1);
    for ($chunk = 0; $chunk < 90; ++$chunk) {
        $values = [];
        for ($i = 0; $i < 500; ++$i) {
            array_push($values, batchId(1 + ($chunk % 9)), hash('xxh128', jobId(10000 + $chunk * 500 + $i)));
        }
        $pdo->prepare('insert into job_outcomes(batch_id,job_id_hash) values ' . implode(',', array_fill(0, 500, '(?,?)')))->execute($values);
    }
    $pdo->query($engine === 'pgsql' ? 'analyze job_outcomes' : 'analyze table job_outcomes')->closeCursor();
    $plan = $pdo->query("explain select retry_id from job_outcomes where batch_id='" . batchId(0) . "' and retry_id is not null")->fetchAll(PDO::FETCH_ASSOC);
    // Contrast the dense-failure batch with a large batch having one outstanding ID.
    $pdo->prepare('insert into job_outcomes values (?,?,?)')->execute([batchId(1), hash('xxh128', 'single-failure'), 'single-failure']);
    $pdo->query($engine === 'pgsql' ? 'analyze job_outcomes' : 'analyze table job_outcomes')->closeCursor();
    $sparse = $pdo->query("explain select retry_id from job_outcomes where batch_id='" . batchId(1) . "' and retry_id is not null")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['settings' => $config, 'explain' => $plan, 'sparse_explain' => $sparse], JSON_THROW_ON_ERROR),"\n";
}
