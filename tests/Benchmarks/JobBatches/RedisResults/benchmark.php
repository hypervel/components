<?php

declare(strict_types=1);

use Hypervel\Bus\Batch;
use Hypervel\Bus\BatchFactory;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Bus\RedisBatchRepository;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\MariaDbConnection;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\PostgresConnection;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisManager;
use Hypervel\Redis\RedisSentinelFactory;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

class Metrics
{
    public static int $writes = 0;

    public static int $statements = 0;

    public static int $transactions = 0;

    public static int $callbacks = 0;
}

class CountingStatement extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        ++Metrics::$statements;
        if (preg_match('/^\s*(insert|update|delete|with inserted)/i', $this->queryString)) {
            ++Metrics::$writes;
        }
        return parent::execute($params);
    }
}

class CountingPdo extends PDO
{
    public function commit(): bool
    {
        $result = parent::commit();
        ++Metrics::$transactions;
        return $result;
    }
}

class BatchProgress
{
    public function __invoke(Batch $batch): void
    {
        ++Metrics::$callbacks;
        $batch->progress();
    }
}

function openPdo(bool $countStatements = false): PDO
{
    $class = $countStatements ? CountingPdo::class : PDO::class;
    $pdo = new $class(getenv('BENCH_DSN'), getenv('BENCH_USER') ?: null, getenv('BENCH_PASSWORD') ?: null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    if ($countStatements) {
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class]);
    }
    return $pdo;
}

function benchRedis(): Redis
{
    $client = new Redis;
    $client->connect('127.0.0.1', (int) getenv('BENCH_REDIS_PORT'), 5.0);

    return $client;
}

function scriptCalls(Redis $redis): int
{
    $stats = $redis->info('commandstats');
    $calls = 0;
    foreach (['cmdstat_eval', 'cmdstat_evalsha'] as $key) {
        if (isset($stats[$key]) && preg_match('/calls=(\d+)/', $stats[$key], $matches)) {
            $calls += (int) $matches[1];
        }
    }

    return $calls;
}

function repository(string $engine, string $variant): DatabaseBatchRepository
{
    $class = match ($engine) {
        'mysql' => MySqlConnection::class,
        'mariadb' => MariaDbConnection::class,
        'pgsql' => PostgresConnection::class,
    };
    $db = new $class(openPdo(true), 'batchbench', '', ['driver' => $engine, 'name' => 'benchmark']);
    $db->setTransactionManager(new DatabaseTransactionsManager);
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

    if ($variant === 'sql') {
        return new DatabaseBatchRepository(new BatchFactory($queue), $resolver, 'job_batches');
    }
    $app = Container::getInstance();
    $app->instance(ContainerContract::class, $app);
    $config = new RedisConfig(new Repository(['database' => ['redis' => [
        'options' => ['prefix' => ''],
        'benchmark' => ['host' => '127.0.0.1', 'port' => (int) getenv('BENCH_REDIS_PORT')],
    ]]]));
    $app->instance(RedisConfig::class, $config);
    $redis = new RedisManager($app, new PoolManager($app), $config, new RedisSentinelFactory);

    return new RedisBatchRepository(
        new BatchFactory($queue),
        $resolver,
        'job_batches',
        'benchmark',
        'job_batch_results',
        $redis,
        'benchmark',
        'job_batch_deletions'
    );
}

function jobId(int $id): string
{
    return sprintf('b22dbb70-36e8-4918-8500-%012d', $id);
}

function batchId(int $worker): string
{
    return sprintf('c22dbb70-36e8-4918-8500-%012d', $worker);
}

function parentFor(string $case, int $worker, int $job): int
{
    return match ($case) {
        'independent' => $worker,
        'singleton' => $job,
        default => 0,
    };
}

function setup(PDO $pdo, string $engine, string $variant, string $case, int $jobs, int $workers): void
{
    $mysql = $engine !== 'pgsql';
    $pdo->exec('drop table if exists job_batch_results');
    $pdo->exec('drop table if exists job_batches');
    $idType = 'varchar(255)';
    $text = $mysql ? 'mediumtext' : 'text';
    $pdo->exec("create table job_batches(id {$idType} primary key, name varchar(255) not null,
        total_jobs int not null, pending_jobs int not null, failed_jobs int not null,
        outstanding_failed_jobs int not null, options {$text}, cancelled_at int, created_at int not null,
        finished_at int, previous_finished_at int, previous_finished_total int, previous_first_pass_total int, previous_success_total int, driver varchar(255) not null)");
    $pdo->exec('create index batch_pruning on job_batches(finished_at, created_at)');
    $pdo->exec("create table job_batch_results(batch_id {$idType} not null, job_id_hash char(32) not null,
        retry_id text, primary key(batch_id,job_id_hash))");
    if (! $mysql) {
        $pdo->exec('create index batch_failures on job_batch_results(batch_id) where retry_id is not null');
    }
    $redis = benchRedis();
    $redis->flushDB();
    $pdo->exec('drop table if exists job_batch_deletions');
    $pdo->exec("create table job_batch_deletions(batch_id {$idType} primary key, driver varchar(255) not null)");
    $failures = in_array($case, ['heavy', 'progress'], true) ? 5000 : 0;
    $options = ['allowFailures' => true];
    if ($case === 'progress') {
        $options['progress'] = [new BatchProgress];
    }
    $encoded = serialize($options);
    $parents = match ($case) {
        'independent' => $workers,
        'singleton' => $jobs,
        default => 1,
    };
    $total = intdiv($jobs, $parents) + $failures;
    $insert = $pdo->prepare('insert into job_batches (id,name,total_jobs,pending_jobs,failed_jobs,outstanding_failed_jobs,options,created_at,driver) values (?,?,?,?,?,?,?,?,?)');
    $pdo->beginTransaction();
    for ($i = 0; $i < $parents; ++$i) {
        $insert->execute([batchId($i), 'benchmark', $total, $total, $variant === 'sql' ? $failures : 0,
            $variant === 'sql' ? $failures : 0, $mysql ? $encoded : base64_encode($encoded), time(), $variant === 'sql' ? 'database' : 'redis']);
    }
    if ($failures) {
        $id = batchId(0);
        foreach (array_chunk(range(1, $failures), 500) as $chunk) {
            $values = [];
            $fields = [];
            foreach ($chunk as $n) {
                $identity = hash('xxh128', jobId($n));
                $fields[$identity] = jobId($n);
                array_push($values, $id, $identity, jobId($n));
            }
            if ($variant === 'sql') {
                $pdo->prepare('insert into job_batch_results values ' . implode(',', array_fill(0, count($chunk), '(?,?,?)')))->execute($values);
            } else {
                $redis->hMSet("batches:{{$id}}:failures", $fields);
            }
        }
        if ($variant !== 'sql') {
            $redis->hMSet("batches:{{$id}}:state", ['known_total' => $total, 'failed_count' => $failures]);
        }
    }
    $pdo->commit();
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
            foreach ($sockets as $inheritedSocket) {
                fclose($inheritedSocket);
            }
            try {
                $repo = repository($engine, $variant);
                $batch = batchId($case === 'independent' ? $worker : 0);
                $warm = $repo->find($batch);
                check($warm !== null && $warm->pendingJobs >= 0, 'Warm connection');
                Metrics::$statements = Metrics::$writes = Metrics::$transactions = 0;
                fwrite($child, "ready\n");
                check(fgets($child) === "go\n", 'Start barrier');
                for ($i = $worker; $i < $jobs; $i += $workers) {
                    $job = jobId(100000 + $i);
                    $batch = batchId(parentFor($case, $worker, $i));
                    $repo->find($batch)->recordSuccessfulJob($job);
                }
                fwrite($child, json_encode([
                    'finished' => hrtime(true),
                    'counts' => [
                        'sql_statements' => Metrics::$statements,
                        'sql_transactions' => Metrics::$transactions,
                        'sql_write_statements' => Metrics::$writes,
                        'callbacks' => Metrics::$callbacks,
                    ],
                ], JSON_THROW_ON_ERROR) . "\n");
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
    $redis = benchRedis();
    $beforeScripts = scriptCalls($redis);
    $start = hrtime(true);
    foreach ($sockets as $socket) {
        fwrite($socket, "go\n");
    }
    $sums = [];
    $end = $start;
    foreach ($sockets as $socket) {
        $stats = json_decode((string) fgets($socket), true, flags: JSON_THROW_ON_ERROR);
        $end = max($end, $stats['finished']);
        foreach ($stats['counts'] as $key => $value) {
            $sums[$key] = ($sums[$key] ?? 0) + $value;
        }
        fclose($socket);
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Worker exit');
    }
    $sums['redis_script_calls'] = scriptCalls($redis) - $beforeScripts;
    $bytes = logPosition($pdo, $engine) - $before;
    $failures = in_array($case, ['heavy', 'progress'], true) ? 5000 : 0;
    $rows = $pdo->query('select * from job_batches')->fetchAll(PDO::FETCH_OBJ);
    $verify = repository($engine, $variant);
    $recorded = 0;
    foreach ($rows as $row) {
        $view = $verify->find($row->id);
        check($view->pendingJobs === $failures && $view->failedJobs === $failures && $view->finished()
            && $row->finished_at !== null && count($view->failedJobIds) === $failures, 'Final counters or finish');
        $recorded += $variant === 'sql' ? 0 : $redis->sCard("batches:{{$row->id}}:successes");
    }
    if ($variant === 'sql') {
        $recorded = (int) $pdo->query('select count(*) from job_batch_results where retry_id is null')->fetchColumn();
    }
    check($recorded === $jobs, 'Distinct successes');
    check($sums['callbacks'] === ($case === 'progress' ? $jobs : 0), 'Progress callbacks');
    return ['engine' => $engine, 'variant' => $variant, 'case' => $case, 'jobs' => $jobs, 'workers' => $workers,
        'seconds' => ($end - $start) / 1e9, ...$sums, 'parents' => count($rows), 'log_bytes' => $bytes];
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    [, $engine, $variant, $case, $jobs, $workers] = $argv;
    echo json_encode(runSample($engine, $variant, $case, (int) $jobs, (int) $workers), JSON_THROW_ON_ERROR), PHP_EOL;
}
