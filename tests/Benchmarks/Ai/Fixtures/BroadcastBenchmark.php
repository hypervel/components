<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\Ai\Fixtures;

use Closure;
use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Broadcasting\Channel;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Engine\Channel as CoroutineChannel;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\RedisTestConfiguration;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

use function Hypervel\Coroutine\parallel;

class BroadcastBenchmark extends TestCase
{
    use InteractsWithRedis;

    /**
     * Require local services and an explicit benchmark run.
     */
    protected function setUp(): void
    {
        if (getenv('BENCH_SMOKE') !== '1' && getenv('BENCH_IDLE_CONFIRMED') !== '1') {
            $this->fail('Use BENCH_SMOKE=1 or BENCH_IDLE_CONFIRMED=1.');
        }

        if (! getenv('BENCH_REDIS_PORT') || ! getenv('BENCH_BROADCAST_PORT') || ! getenv('BENCH_CA')) {
            $this->fail('Supply disposable loopback Redis and broadcast origin ports, and their TLS certificate.');
        }

        parent::setUp();
    }

    /**
     * Route real broadcaster and queue operations to disposable local services.
     */
    protected function defineEnvironment(Application $app): void
    {
        $this->assertFalse(RedisTestConfiguration::usesCluster(), 'This benchmark requires standalone Redis.');
        $config = $app->make('config');
        $connection = $config->array('database.redis.default');
        $clientOptions = ['verify' => getenv('BENCH_CA'), 'proxy' => ''];
        if (getenv('BENCH_RETAIN_CONNECTIONS') === '1') {
            // Isolate idle-handle retention from batching; these cases send synchronously through cURL.
            $clientOptions['handler'] = HandlerStack::create(new CurlHandler(['handle_factory' => new CurlFactory(256)]));
        }
        $config->set([
            'database.redis' => [
                'options' => ['prefix' => ''],
                'default' => array_replace($connection, [
                    'url' => null, 'host' => '127.0.0.1', 'port' => (int) getenv('BENCH_REDIS_PORT'),
                    'username' => null, 'password' => null, 'options' => ['prefix' => ''],
                ]),
            ],
            'broadcasting.default' => 'benchmark',
            'broadcasting.connections.benchmark' => [
                'driver' => 'pusher', 'key' => 'benchmark', 'secret' => 'benchmark', 'app_id' => 'benchmark',
                'options' => ['host' => '127.0.0.1', 'port' => (int) getenv('BENCH_BROADCAST_PORT'), 'scheme' => 'https', 'useTLS' => true, 'timeout' => 10],
                'client_options' => $clientOptions,
            ],
            'cache.default' => 'redis',
            'cache.stores.redis.connection' => 'default',
            'cache.stores.redis.lock_connection' => 'default',
            'queue.default' => 'benchmark',
        ]);
    }

    #[DataProvider('broadcastModes')]
    public function testBroadcasts(int $streams, string $mode, int $delay, bool $backlog = false, ?int $batchMilliseconds = null): void
    {
        $connection = $this->createRedisConnectionWithOptions('broadcast_benchmark', ['prefix' => '', 'serializer' => 0], 40);
        config(['queue.connections.benchmark' => [
            'driver' => 'redis', 'connection' => $connection, 'queue' => 'ai-benchmark',
            'retry_after' => 60, 'block_for' => 1, 'after_commit' => false,
        ]]);
        $queue = Queue::connection('benchmark');
        $client = new Client([
            'base_uri' => 'https://127.0.0.1:' . getenv('BENCH_BROADCAST_PORT'),
            'verify' => getenv('BENCH_CA'), 'timeout' => 10, 'proxy' => '',
        ]);
        $jobs = 0;
        $jobBytes = 0;
        $failedJobs = 0;
        app(Dispatcher::class)->listen(JobQueued::class, static function (JobQueued $event) use (&$jobs, &$jobBytes): void {
            ++$jobs;
            $jobBytes += strlen($event->payload);
        });
        app(Dispatcher::class)->listen(JobFailed::class, static function () use (&$failedJobs): void {
            ++$failedJobs;
        });
        $smoke = getenv('BENCH_SMOKE') === '1';
        $probe = getenv('BENCH_PROBE') === '1';
        $chunks = $smoke ? 4 : 32;
        $concurrency = $mode === 'queued' ? 16 : 0;
        $expected = $streams * ($chunks + 4);

        for ($sample = 0; $sample <= ($smoke || $probe ? 0 : 5); ++$sample) {
            $client->post('/reset', ['query' => ['delay_us' => $delay]])->getBody()->close();
            $this->assertSame(0, $queue->size());
            $jobs = $jobBytes = $failedJobs = 0;
            $ready = $indexes = $completed = $consumed = $callbacks = $constituents = [];
            $remaining = $streams;
            $sends = 0;
            $operations = [];
            $worker = null;
            $workerExit = null;
            $workerOperation = null;
            $workerOptions = null;
            if ($mode === 'queued') {
                $worker = new class(app('queue'), app(Dispatcher::class), app(ExceptionHandler::class), static fn (): bool => false) extends Worker {
                    /**
                     * Leave the test process's signal handlers untouched.
                     */
                    protected function supportsAsyncSignals(): bool
                    {
                        return false;
                    }
                };
                $worker->setCache(app('cache')->store());
                $workerOptions = new WorkerOptions(
                    concurrency: $concurrency,
                    maxJobs: $expected,
                    maxTime: 30,
                    sleep: 0,
                    memory: 1024,
                );
                $workerOperation = static function () use ($worker, $workerOptions, &$workerExit): void {
                    $workerExit = $worker->daemon('benchmark', 'ai-benchmark', $workerOptions);
                };
                if (! $backlog) {
                    $operations[] = $workerOperation;
                }
            }
            gc_collect_cycles();
            memory_reset_peak_usage();
            $cpuBefore = self::cpuMicroseconds();
            $started = hrtime(true);

            for ($stream = 0; $stream < $streams; ++$stream) {
                $operations[] = static function () use ($stream, $streams, $chunks, $mode, $started, &$ready, &$indexes, &$completed, &$consumed, &$callbacks, &$constituents, &$sends, &$remaining, &$jobs, $worker, $workerOptions, $batchMilliseconds): void {
                    try {
                        $response = new StreamableAgentResponse('invocation-' . $stream, static function () use ($stream, $streams, $chunks, $started, &$ready, &$indexes): Generator {
                            $events = [new StreamStart($stream . '-start', 'benchmark', 'model', time()), new TextStart($stream . '-text-start', 'message-' . $stream, time())];
                            for ($chunk = 0; $chunk < $chunks; ++$chunk) {
                                $events[] = new TextDelta($stream . '-delta-' . $chunk, 'message-' . $stream, str_repeat('x', 8), time());
                            }
                            $events[] = new TextEnd($stream . '-text-end', 'message-' . $stream, time());
                            $events[] = new StreamEnd($stream . '-end', 'stop', new TextUsage(1, $chunks), time());

                            foreach ($events as $index => $event) {
                                // Fixed availability times expose broadcaster backpressure without slowing the simulated provider.
                                $available = $started + intdiv($stream * 20_000_000, $streams) + max(0, min($chunks - 1, $index - 2)) * 20_000_000;
                                $ready[$event->id] = $available;
                                $indexes[$event->id] = $index;
                                while (($wait = $available - hrtime(true)) > 0) {
                                    usleep((int) ceil($wait / 1000));
                                }
                                yield $event;
                            }
                        });
                        if ($mode !== 'none') {
                            $send = static function (StreamEvent $event, array $ids) use ($stream, $mode, &$callbacks, &$constituents, &$sends): void {
                                $constituents[$event->id] = $ids;
                                ++$sends;
                                $before = hrtime(true);
                                $event->broadcast(new Channel('benchmark-' . $stream), now: $mode === 'immediate');
                                $callbacks[] = (hrtime(true) - $before) / 1e6;
                            };
                            $produce = static function (Closure $accept) use ($response, &$consumed): void {
                                $response->each($accept);
                                $consumed[] = hrtime(true);
                            };
                            if ($batchMilliseconds === null) {
                                $produce(static fn (StreamEvent $event) => $send($event, [$event->id]));
                            } else {
                                BroadcastBatcher::run($produce, $send, $batchMilliseconds);
                            }
                        } else {
                            foreach ($response as $event);
                            $consumed[] = hrtime(true);
                        }
                        $completed[] = hrtime(true);
                    } catch (Throwable $exception) {
                        if ($worker !== null) {
                            $worker->shouldQuit = true;
                        }
                        throw $exception;
                    } finally {
                        if (--$remaining === 0 && $workerOptions !== null) {
                            // Coalescing determines the final job count only after every sender has finished.
                            $workerOptions->maxJobs = $jobs;
                        }
                    }
                };
            }

            parallel($operations);
            $producerCpu = $workerCpu = $workerDrain = null;
            if ($backlog) {
                $producerCpu = self::cpuMicroseconds() - $cpuBefore;
                $workerCpuBefore = self::cpuMicroseconds();
                $workerStarted = hrtime(true);
                $workerOperation();
                $workerDrain = (hrtime(true) - $workerStarted) / 1e6;
                $workerCpu = self::cpuMicroseconds() - $workerCpuBefore;
            }
            $finished = hrtime(true);
            $cpu = self::cpuMicroseconds() - $cpuBefore;
            $peakHeap = memory_get_peak_usage();
            $statistics = json_decode((string) $client->get('/stats')->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $records = $statistics['records'];
            $this->assertCount($streams, $completed);
            $this->assertCount($expected, $ready);
            $this->assertCount($sends, $records);
            $this->assertSame(0, $statistics['active']);
            $this->assertSame($mode === 'queued' ? Worker::EXIT_SUCCESS : null, $workerExit);
            $this->assertSame($mode === 'queued' ? $sends : 0, $jobs);
            if ($batchMilliseconds === null) {
                $this->assertSame($mode === 'none' ? 0 : $expected, $sends);
            }
            $this->assertSame(0, $failedJobs);
            $this->assertSame(0, $queue->size());
            $latencies = [];
            $ids = [];
            $textBytes = 0;
            $highestIndexes = [];
            $outOfOrder = 0;
            foreach ($records as $record) {
                $this->assertArrayHasKey($record['id'], $constituents);
                $this->assertSame('benchmark-' . explode('-', $record['id'])[0], $record['channel']);
                foreach ($constituents[$record['id']] as $id) {
                    $this->assertArrayHasKey($id, $ready);
                    $this->assertArrayNotHasKey($id, $ids);
                    $ids[$id] = true;
                    $highest = $highestIndexes[$record['channel']] ?? -1;
                    $index = $indexes[$id];
                    $outOfOrder += (int) ($index < $highest);
                    $highestIndexes[$record['channel']] = max($highest, $index);
                    if (! $backlog) {
                        // hrtime() uses the same host-wide monotonic clock in the client and receiver processes.
                        $latencies[] = ($record['accepted_ns'] - $ready[$id]) / 1e6;
                    }
                }
                $textBytes += strlen($record['delta'] ?? '');
            }
            $this->assertCount($mode === 'none' ? 0 : $expected, $ids);
            $this->assertSame($mode === 'none' ? 0 : $streams * $chunks * 8, $textBytes);
            $producerFinished = max($completed);
            $lastAccepted = $records === [] ? $producerFinished : max(array_column($records, 'accepted_ns'));
            $connections = array_unique(array_column($records, 'connection'));

            if (! $smoke && ! $probe && $sample === 0) {
                continue;
            }

            file_put_contents((string) getenv('BENCH_OUT'), json_encode([
                'case' => $streams . '.' . $mode . '.' . $delay . ($backlog ? '.backlog' : '') . ($batchMilliseconds === null ? '' : '.batch' . $batchMilliseconds), 'sample' => $sample, 'smoke' => $smoke, 'probe' => $probe,
                'batch_ms' => $batchMilliseconds, 'original_events' => $expected,
                'retain_connections' => getenv('BENCH_RETAIN_CONNECTIONS') === '1',
                'events_consumed_ms' => (max($consumed) - $started) / 1e6,
                'streams' => $streams, 'chunks' => $chunks, 'pace_us' => 20000, 'broker_delay_us' => $delay,
                'worker_concurrency' => $concurrency, 'producer_ms' => ($producerFinished - $started) / 1e6,
                'producer_cpu_ms' => $producerCpu === null ? null : $producerCpu / 1000,
                'worker_cpu_ms' => $workerCpu === null ? null : $workerCpu / 1000,
                'worker_drain_ms' => $workerDrain, 'jobs_per_second' => $workerDrain === null ? null : $expected / ($workerDrain / 1000),
                'total_ms' => ($finished - $started) / 1e6, 'delivery_drain_ms' => max(0, $lastAccepted - $producerFinished) / 1e6,
                'worker_stop_ms' => ($finished - max($lastAccepted, $producerFinished)) / 1e6,
                'last_accept_ms' => $records === [] ? null : (max(array_column($records, 'accepted_ns')) - $started) / 1e6,
                'accept_latency_ms' => self::percentiles($latencies), 'callback_ms' => self::percentiles($callbacks),
                'client_cpu_ms' => $cpu / 1000, 'peak_heap' => $peakHeap, 'broadcast_requests' => count($records),
                'request_body_bytes' => array_sum(array_column($records, 'body_bytes')), 'peak_broker_requests' => $statistics['peak'],
                'queue_jobs' => $jobs, 'queue_payload_bytes' => $jobBytes, 'out_of_order_events' => $outOfOrder,
                'receiver_connections' => count($connections),
                'new_receiver_connections' => count(array_filter($connections, static fn (int $connection): bool => $connection > $statistics['accepted_at_reset'])),
            ], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND);
        }
    }

    /**
     * Compare no broadcasts, immediate sends and real Redis queue delivery.
     */
    public static function broadcastModes(): array
    {
        $cases = [];
        foreach ([1, 16] as $streams) {
            $cases[$streams . ' none'] = [$streams, 'none', 0];
            foreach ([5000, 50000] as $delay) {
                foreach (['immediate', 'queued'] as $mode) {
                    $cases[$streams . ' ' . $mode . ' ' . $delay] = [$streams, $mode, $delay];
                }
            }
        }
        $cases['16 queued backlog 5000'] = [16, 'queued', 5000, true];
        foreach ([5000, 50000] as $delay) {
            foreach (['immediate', 'queued'] as $mode) {
                foreach ([0, 50] as $milliseconds) {
                    $cases['16 ' . $mode . ' batch ' . $milliseconds . ' ' . $delay] = [16, $mode, $delay, false, $milliseconds];
                }
            }
        }
        return $cases;
    }

    public function testBatchingFlushesWhileTheProducerIsQuiet(): void
    {
        $delivered = new CoroutineChannel(1);
        $sent = [];
        try {
            BroadcastBatcher::run(
                function (Closure $push) use ($delivered): void {
                    $push(new TextDelta('first', 'message', 'Hello', 1));
                    $this->assertTrue($delivered->pop(1), 'The sender waited for another event instead of flushing during silence.');
                    $push(new TextDelta('second', 'message', ' world', 1));
                },
                static function (StreamEvent $event, array $ids) use ($delivered, &$sent): void {
                    $sent[] = $event;
                    if ($event->id === 'first') {
                        $delivered->push(true);
                    }
                },
                50,
            );
            $this->assertSame(['Hello', ' world'], array_column($sent, 'delta'));
        } finally {
            $delivered->close();
        }
    }

    public function testBatchingPreservesReplayAndEncodedTextBoundaries(): void
    {
        $emoji = str_repeat('😀', 200);
        $accented = str_repeat('é', 100);
        $events = [
            new TextStart('start', 'one', 1),
            new TextDelta('first', 'one', $emoji, 1),
            new TextDelta('second', 'one', $emoji, 2),
            new TextDelta('third', 'one', $accented, 3),
            new TextDelta('fourth', 'two', 'other message', 4),
            (new TextDelta('fifth', 'two', 'other invocation', 5))->withInvocationId('another'),
            new TextEnd('end', 'two', 6),
        ];
        $response = new StreamableAgentResponse('invocation', static function () use ($events): Generator {
            yield from $events;
        });
        $sent = $constituents = [];
        BroadcastBatcher::run(
            static fn (Closure $push) => $response->each($push),
            static function (StreamEvent $event, array $ids) use (&$sent, &$constituents): void {
                $sent[] = $event;
                $constituents[] = $ids;
            },
            1000,
        );
        $this->assertSame([['start'], ['first'], ['second', 'third'], ['fourth'], ['fifth'], ['end']], $constituents);
        $this->assertSame($emoji . $accented, $sent[2]->delta);
        $this->assertSame(2, $sent[2]->timestamp);
        $this->assertSame('another', $sent[4]->invocationId);
        $this->assertSame($events, $response->events->all());
        $this->assertSame($emoji, $events[2]->delta);
        foreach ($sent as $event) {
            if ($event instanceof TextDelta) {
                $this->assertLessThanOrEqual(4096, strlen(json_encode($event->delta, JSON_THROW_ON_ERROR)) - 2);
            }
        }
    }

    /**
     * Return latency percentiles without timing assertions.
     */
    private static function percentiles(array $values): array
    {
        if ($values === []) {
            return [];
        }
        sort($values);
        $result = [];
        foreach (['p50' => .5, 'p95' => .95, 'p99' => .99, 'max' => 1] as $name => $percentile) {
            $result[$name] = $values[(int) ceil(count($values) * $percentile) - 1];
        }
        return $result;
    }

    /**
     * Return process CPU time, including the in-process queue consumers.
     */
    private static function cpuMicroseconds(): int
    {
        $usage = getrusage();
        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000000 + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];
    }
}
