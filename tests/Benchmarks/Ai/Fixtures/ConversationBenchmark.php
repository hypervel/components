<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\Ai\Fixtures;

use Closure;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\AnonymousAgent;
use Hypervel\Ai\Approvals\ApprovalClaim;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Ai\Storage\StoredTurn;
use Hypervel\Contracts\ConnectionPool\Connection as PoolConnection;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Events\ConnectionEvent;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Database\Pool\DatabasePool;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Ai\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Coroutine\parallel;

class ConversationBenchmark extends TestCase
{
    private bool $recording = false;

    /** @var array<string, int> */
    private array $statements = [];

    /**
     * Require a disposable database.
     */
    protected function setUp(): void
    {
        $this->assertNotFalse(filter_var(getenv('BENCH_DB_PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]), 'Set BENCH_DB_PORT to a disposable loopback database.');
        $this->assertContains(getenv('BENCH_DB_DRIVER'), ['pgsql', 'mysql']);
        parent::setUp();

        app(Dispatcher::class)->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if ($this->recording && $event->connectionName === 'benchmark') {
                $type = strtolower(strtok(ltrim($event->sql), ' '));
                $this->statements[$type] = ($this->statements[$type] ?? 0) + 1;

                if (in_array($type, ['insert', 'update', 'delete'], true) && $event->connection->transactionLevel() === 0) {
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
     * Use only the disposable instance, with real pooling and committed migrations.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $driver = (string) getenv('BENCH_DB_DRIVER');
        $config->set([
            'database.default' => 'benchmark',
            'database.connections' => ['benchmark' => [
                'driver' => $driver,
                'host' => '127.0.0.1',
                'port' => (int) getenv('BENCH_DB_PORT'),
                'database' => 'ai_benchmark',
                'username' => $driver === 'pgsql' ? 'postgres' : 'root',
                'password' => 'benchmark',
                'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'prefix' => '',
                ...($driver === 'mysql' ? ['strict' => true, 'collation' => 'utf8mb4_unicode_ci'] : []),
                'pool' => [
                    'testing_enabled' => true,
                    'min_retained_connections' => 0,
                    'heartbeat_interval' => null,
                    'idle_check_interval' => null,
                ],
            ]],
            'ai.providers.openai' => ['driver' => 'openai', 'key' => 'benchmark-key'],
        ]);
        $app->bind(DatabasePool::class, ConversationBenchmarkPool::class);
    }

    #[DataProvider('turnModes')]
    public function testTurns(bool $atomic, int $concurrency, bool $shared): void
    {
        $store = new DatabaseConversationStore;
        $prompt = $this->prompt();
        $response = new AgentResponse('benchmark', str_repeat('a', 1024), new TextUsage(32, 64), new Meta('openai', 'benchmark'));
        $requests = getenv('BENCH_SMOKE') === '1' ? 16 : 160;

        for ($sample = -1; $sample < $this->samples(); ++$sample) {
            $conversations = [];

            for ($index = 0; $index < ($shared ? 1 : $concurrency); ++$index) {
                $conversations[] = $store->storeConversation('user', 1, 'Benchmark');
            }

            $operations = [];

            for ($index = 0; $index < $requests; ++$index) {
                $conversation = $conversations[$index % count($conversations)];
                $operations[] = static function () use ($atomic, $store, $conversation, $prompt, $response): StoredTurn {
                    if ($atomic) {
                        return $store->storeTurn($conversation, null, 'user', 1, $prompt, $response);
                    }

                    $user = $store->storeUserMessage($conversation, 'user', 1, $prompt->agent::class, new UserMessage($prompt->prompt));
                    $assistant = $store->storeAssistantMessage($conversation, 'user', 1, $prompt, $response);

                    return new StoredTurn($conversation, $user, $assistant);
                };
            }

            $results = $this->measure('turn.' . ($atomic ? 'atomic' : 'separate') . '.' . $concurrency . '.' . ($shared ? 'shared' : 'distinct'), $sample, $operations, $concurrency);
            $this->assertCount($requests, $results);
            $this->assertSame($requests * 2, DB::table('agent_conversation_messages')->count());
            $this->assertSame($requests * ($atomic ? 1 : 2), $this->statements['update']);
            $this->assertSame($requests * 2, $this->statements['insert']);
            $this->assertSame($requests, $this->statements['begin']);
            $this->assertSame($requests, $this->statements['commit']);
            $this->assertSame($atomic ? 0 : $requests * 2, $this->statements['autocommit_writes'] ?? 0);
            DB::table('agent_conversations')->delete();
        }
    }

    /**
     * Compare turn write and commit counts with distinct and contended conversations.
     */
    public static function turnModes(): array
    {
        $cases = [];

        foreach ([false, true] as $atomic) {
            foreach ([[1, false], [16, false], [16, true]] as [$concurrency, $shared]) {
                $cases[($atomic ? 'atomic' : 'separate') . ' ' . $concurrency . ' ' . ($shared ? 'shared' : 'distinct')] = [$atomic, $concurrency, $shared];
            }
        }

        return $cases;
    }

    #[DataProvider('approvalSizes')]
    public function testApprovalResults(int $tools, int $bytes): void
    {
        $store = new DatabaseConversationStore;
        $callIds = array_map(static fn (int $index): string => 'call-' . $index, range(1, $tools));
        $payload = str_repeat('r', $bytes);
        $requests = getenv('BENCH_SMOKE') === '1' ? 2 : 8;

        for ($sample = -1; $sample < $this->samples(); ++$sample) {
            $operations = DB::transaction(function () use ($store, $requests, $callIds, $payload): array {
                $operations = [];

                for ($index = 0; $index < $requests; ++$index) {
                    $conversation = $store->storeConversation('user', 1, 'Approvals');
                    $this->storePaused($store, $conversation, $callIds);
                    $operations[] = static function () use ($store, $conversation, $callIds, $payload): ApprovalClaim {
                        $claim = $store->claimPendingApprovals($conversation, $callIds)
                            ?? throw new LogicException('The benchmark pause was not claimed.');

                        foreach ($callIds as $id) {
                            $store->recordApprovalResult($claim, new ToolResult($id, 'lookup', [], $payload));
                        }

                        return $claim;
                    };
                }

                return $operations;
            });

            $claims = $this->measure('approvals.' . $tools . '.' . $bytes, $sample, $operations);
            $this->assertSame($requests * ($tools + 1), $this->statements['begin']);
            $this->assertSame($requests * ($tools + 1), $this->statements['commit']);
            $this->assertSame($requests * ($tools + 1), $this->statements['update']);

            foreach ($claims as $claim) {
                $record = DB::table('agent_conversation_messages')->where('id', $claim->messageId)->first(['steps', 'approval_claim']);
                $this->assertSame($claim->token, $record->approval_claim);
                $calls = json_decode($record->steps, true, flags: JSON_THROW_ON_ERROR)[0]['tool_calls'];
                $this->assertSame(array_fill(0, $tools, $payload), array_column($calls, 'result'));
            }

            DB::table('agent_conversations')->delete();
        }
    }

    /**
     * Vary the number of durable outcomes and the size of their growing JSON payload.
     */
    public static function approvalSizes(): array
    {
        return ['one small' => [1, 128], 'five small' => [5, 128], 'twenty small' => [20, 128], 'five large' => [5, 8192]];
    }

    #[DataProvider('historySizes')]
    public function testHistory(int $turns, int $bytes): void
    {
        $store = new DatabaseConversationStore;
        $conversation = $store->storeConversation('user', 1, 'History');
        $prompt = $this->prompt();
        $response = (new AgentResponse('benchmark', 'Answer', new TextUsage, new Meta('openai', 'benchmark')))->withSteps(collect([
            new Step('Answer', [new ToolCall('call', 'lookup', [])], [new ToolResult('call', 'lookup', [], str_repeat('r', $bytes))], FinishReason::Stop, new TextUsage, new Meta('openai', 'benchmark'), '', []),
        ]));

        DB::transaction(static function () use ($turns, $store, $conversation, $prompt, $response): void {
            for ($index = 0; $index < $turns; ++$index) {
                $store->storeTurn($conversation, null, 'user', 1, $prompt, $response);
            }
        });

        // Match RemembersConversations::maxConversationMessages()'s default window.
        $operations = array_fill(0, getenv('BENCH_SMOKE') === '1' ? 2 : 20, static fn (): int => $store->getLatestConversationMessages($conversation, 100)->count());

        for ($sample = -1; $sample < $this->samples(); ++$sample) {
            $counts = $this->measure('history.' . $turns . '.' . $bytes, $sample, $operations);
            $this->assertSame(array_fill(0, count($operations), min($turns, 50) * 3), $counts);
            $this->assertSame(['select' => count($operations)], $this->statements);
        }
    }

    /**
     * Compare bounded history windows with small and larger tool results.
     */
    public static function historySizes(): array
    {
        return ['20 small' => [20, 128], '200 small' => [200, 128], '20 large' => [20, 8192], '200 large' => [200, 8192]];
    }

    #[DataProvider('replaySizes')]
    public function testReplayCleanup(int $eligible): void
    {
        $store = new DatabaseConversationStore;
        $prompt = $this->prompt();
        $response = new AgentResponse('benchmark', 'Done', new TextUsage, new Meta('openai', 'benchmark'));
        $requests = getenv('BENCH_SMOKE') === '1' ? 2 : 8;

        for ($sample = -1; $sample < $this->samples(); ++$sample) {
            $operations = DB::transaction(function () use ($requests, $store, $eligible, $prompt, $response): array {
                $operations = [];

                for ($index = 0; $index < $requests; ++$index) {
                    $conversation = $store->storeConversation('user', 1, 'Replay cleanup');

                    for ($pause = 0; $pause < 20; ++$pause) {
                        $this->storePaused($store, $conversation, ['call-' . $pause], $pause < $eligible ? 4096 : 0);
                    }

                    $operations[] = static fn (): StoredTurn => $store->storeTurn($conversation, null, 'user', 1, $prompt, $response);
                }

                return $operations;
            });

            $this->measure('replay.' . $eligible, $sample, $operations);
            $this->assertSame($requests * ($eligible + 1), $this->statements['update']);
            $this->assertSame($requests * ($eligible + 1), $this->statements['select']);
            $this->assertSame(0, DB::table('agent_conversation_messages')->where('has_replay_blocks', true)->count());
            $this->assertSame($requests * 22, DB::table('agent_conversation_messages')->count());
            DB::table('agent_conversations')->delete();
        }
    }

    /**
     * Keep the paused-row population fixed while varying eligible cleanup work.
     */
    public static function replaySizes(): array
    {
        return ['none' => [0], 'five' => [5], 'all' => [20]];
    }

    /**
     * Seed an ordinary stored approval pause outside measurement.
     *
     * @param list<string> $callIds
     */
    private function storePaused(DatabaseConversationStore $store, string $conversation, array $callIds, int $replayBytes = 0): void
    {
        $calls = array_map(static fn (string $id): ToolCall => new ToolCall($id, 'lookup', []), $callIds);
        $approvals = array_map(static fn (string $id): PendingApproval => new PendingApproval($id, 'lookup', [], 'Approval required'), $callIds);
        $response = (new AgentResponse('benchmark', '', new TextUsage, new Meta('openai', 'benchmark')))
            ->withSteps(collect([new Step('', $calls, [], FinishReason::ToolCalls, new TextUsage, new Meta, '', $replayBytes === 0 ? [] : [['text' => str_repeat('r', $replayBytes)]])]))
            ->withPendingApprovals(collect($approvals));
        $store->storeAssistantMessage($conversation, 'user', 1, $this->prompt(), $response);
    }

    /**
     * Build a real prompt without making provider calls.
     */
    private function prompt(): AgentPrompt
    {
        return new AgentPrompt(new AnonymousAgent('', [], []), 'Explain the document.', [], app(AiManager::class)->textProvider('openai'), 'benchmark');
    }

    /**
     * Keep smoke runs short and collect repeated warm measurements otherwise.
     */
    private function samples(): int
    {
        return getenv('BENCH_SMOKE') === '1' ? 1 : 5;
    }

    /**
     * Measure complete coroutine lifetimes with query, transaction and pool diagnostics.
     *
     * @param list<Closure> $operations
     */
    private function measure(string $case, int $sample, array $operations, int $concurrency = 1): array
    {
        ConnectionResolver::releaseIdleConnections();
        /** @var ConversationBenchmarkPool $pool */
        $pool = app(PoolManager::class)->pool('benchmark');
        $pool->resetMeasurements();
        $this->statements = [];
        $latencies = [];
        $active = 0;
        $peakActive = 0;
        $work = array_map(static function (Closure $operation) use (&$latencies, &$active, &$peakActive): Closure {
            return static function () use ($operation, &$latencies, &$active, &$peakActive): mixed {
                $peakActive = max($peakActive, ++$active);
                $start = hrtime(true);

                try {
                    return $operation();
                } finally {
                    $latencies[] = (hrtime(true) - $start) / 1e6;
                    --$active;
                }
            };
        }, $operations);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = getrusage();
        $this->recording = $pool->recording = true;
        $start = hrtime(true);

        try {
            $results = parallel($work, $concurrency);
        } finally {
            $wall = (hrtime(true) - $start) / 1e6;
            $after = getrusage();
            $this->recording = $pool->recording = false;
        }

        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(0, $pool->getWaitingCount());
        $this->assertCount(count($operations), $latencies);
        $this->assertCount(count($operations), $pool->holds);
        $cpu = ($after['ru_utime.tv_sec'] + $after['ru_stime.tv_sec'] - $before['ru_utime.tv_sec'] - $before['ru_stime.tv_sec']) * 1000
            + ($after['ru_utime.tv_usec'] + $after['ru_stime.tv_usec'] - $before['ru_utime.tv_usec'] - $before['ru_stime.tv_usec']) / 1000;

        if ($sample >= 0) {
            file_put_contents((string) getenv('BENCH_OUT'), json_encode([
                'case' => $case,
                'sample' => $sample,
                'smoke' => getenv('BENCH_SMOKE') === '1',
                'driver' => getenv('BENCH_DB_DRIVER'),
                'operations' => count($operations),
                'concurrency' => $concurrency,
                'peak_active' => $peakActive,
                'cpu_ms_per_operation' => $cpu / count($operations),
                'wall_ms' => $wall,
                'operations_per_second' => count($operations) * 1000 / $wall,
                'callback_ms' => self::percentiles($latencies),
                'checkout_ms' => self::percentiles($pool->checkouts),
                'hold_ms' => self::percentiles($pool->holds),
                'statements' => $this->statements,
                'peak_heap' => memory_get_peak_usage(),
            ], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND);
        }

        return $results;
    }

    /**
     * Summarize bounded sample arrays without imposing timing assertions.
     */
    private static function percentiles(array $values): array
    {
        sort($values, SORT_NUMERIC);

        return [
            'p50' => $values[(int) ceil(count($values) * 0.5) - 1],
            count($values) < 20 ? 'max' : 'p95' => $values[(int) ceil(count($values) * 0.95) - 1],
        ];
    }
}

class ConversationBenchmarkPool extends DatabasePool
{
    public bool $recording = false;

    public array $checkouts = [];

    public array $holds = [];

    private array $borrowedAt = [];

    /**
     * Reset diagnostics between batches, after the setup connection is released.
     */
    public function resetMeasurements(): void
    {
        $this->checkouts = $this->holds = $this->borrowedAt = [];
    }

    /**
     * Include pool queueing and connection creation in checkout latency.
     */
    public function borrow(): PoolConnection
    {
        $started = hrtime(true);
        $connection = parent::borrow();

        if ($this->recording) {
            $acquired = hrtime(true);
            $this->checkouts[] = ($acquired - $started) / 1e6;
            $this->borrowedAt[spl_object_id($connection)] = $acquired;
        }

        return $connection;
    }

    /**
     * End a physical hold before returning its slot to waiting coroutines.
     */
    public function release(PoolConnection $connection): void
    {
        $identity = spl_object_id($connection);

        if (isset($this->borrowedAt[$identity])) {
            $this->holds[] = (hrtime(true) - $this->borrowedAt[$identity]) / 1e6;
            unset($this->borrowedAt[$identity]);
        }

        parent::release($connection);
    }
}
