<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\SignalRegistry;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use Hypervel\Queue\Outbox\OutboxMessage;
use Hypervel\Queue\Outbox\OutboxRelay;
use Hypervel\Queue\Outbox\OutboxWorker;
use Hypervel\Queue\Outbox\OutboxWorkerOptions;
use Hypervel\Queue\Worker;
use Hypervel\Support\Sleep;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class OutboxWorkerTest extends TestCase
{
    protected OutboxRelay $relay;

    protected Cache $cache;

    protected ExceptionHandler $exceptions;

    protected Closure $signalHandler;

    public function testRoundRobinClaimsHaveAnExactJobLimitAndConcurrentPublication(): void
    {
        $worker = $this->worker(['primary', 'secondary']);
        $releaseFirst = new Channel(1);
        $running = 0;
        $peak = 0;
        $finished = 0;
        $this->relay->expects('claim')->with('primary', 2)->andReturn([$this->message(1), $this->message(2)]);
        $this->relay->expects('claim')->with('secondary', 1)->andReturn([$this->message(3)]);
        $this->relay->shouldReceive('deliver')->twice()->andReturnUsing(function (string $database) use ($releaseFirst, &$running, &$peak, &$finished): bool {
            $peak = max($peak, ++$running);

            try {
                if ($database === 'primary') {
                    $this->assertTrue($releaseFirst->pop(2));
                } else {
                    $releaseFirst->push(true);
                }

                ++$finished;

                return true;
            } finally {
                --$running;
            }
        });

        $this->assertSame(Worker::EXIT_SUCCESS, $worker->run(null, new OutboxWorkerOptions(batch: 2, concurrency: 2, maxJobs: 3, memory: 1024)));
        $this->assertSame(2, $peak);
        $this->assertSame(2, $finished);
        $this->assertSame(0, $running);
    }

    public function testFailedSourceCoolsDownWithoutDelayingHealthySources(): void
    {
        Sleep::fake();
        $worker = $this->worker(['broken', 'healthy']);
        $failure = new RuntimeException('Connection refused.');
        $this->relay->expects('claim')->with('broken', 1)->andThrow($failure);
        $this->relay->shouldReceive('claim')->with('healthy', 1)->times(3)->andReturn([$this->message(1)]);
        $this->relay->shouldReceive('deliver')->with('healthy', m::type('array'))->times(3)->andReturn(true);
        $this->exceptions->expects('report')->with($failure, ['outbox_database' => 'broken']);

        $this->assertSame(0, $worker->run(null, new OutboxWorkerOptions(batch: 1, sleep: 60, maxJobs: 3, memory: 1024)));
        Sleep::assertNeverSlept();
    }

    public function testStopWhenEmptyTreatsFailedSourcesAsEmptyAndDoesNotRecheckAfterJoining(): void
    {
        Sleep::fake();
        $worker = $this->worker(['broken', 'empty']);
        $failure = new RuntimeException('Missing table.');
        $this->relay->expects('claim')->with('broken', 100)->andThrow($failure);
        $this->relay->expects('claim')->with('empty', 100)->andReturn([]);
        $this->exceptions->expects('report')->with($failure, ['outbox_database' => 'broken']);

        $this->assertSame(0, $worker->run(null, new OutboxWorkerOptions(memory: 1024, stopWhenEmpty: true)));
        Sleep::assertNeverSlept();
    }

    public function testOnceVisitsEverySourceWithoutSleepingEvenWhenDeliveryFails(): void
    {
        Sleep::fake();
        $worker = $this->worker(['first', 'second']);
        $this->relay->expects('claim')->with('first', 100)->andReturn([$this->message(1)]);
        $this->relay->expects('claim')->with('second', 100)->andReturn([]);
        $this->relay->expects('deliver')->with('first', m::type('array'))->andReturn(false);

        $this->assertSame(0, $worker->run(null, new OutboxWorkerOptions(memory: 1024, once: true)));
        Sleep::assertNeverSlept();
    }

    public function testPinnedSourceDoesNotClaimFromOtherConfiguredDatabases(): void
    {
        $worker = $this->worker(['first', 'second']);
        $this->relay->expects('claim')->with('second', 100)->andReturn([]);

        $this->assertSame(0, $worker->run('second', new OutboxWorkerOptions(memory: 1024, once: true)));
    }

    public function testFailureSleepKeepsThePublicationSlotOccupied(): void
    {
        Sleep::fake();
        $worker = $this->worker();
        $claims = 0;
        $this->relay->shouldReceive('claim')->twice()->andReturnUsing(function () use (&$claims): array {
            return [$this->message(++$claims)];
        });
        $this->relay->expects('deliver')->andReturn(false);
        $this->relay->expects('deliver')->andReturn(true);
        Sleep::whenFakingSleep(function () use (&$claims): void {
            $this->assertSame(1, $claims);
        });

        $this->assertSame(0, $worker->run(null, new OutboxWorkerOptions(batch: 1, sleep: 0.25, maxJobs: 2, memory: 1024)));
        Sleep::assertSequence([Sleep::usleep(250_000)]);
    }

    public function testEmptyPassSleepsOnceAndSignalStopsFurtherAdmission(): void
    {
        Sleep::fake();
        $worker = $this->worker(['first', 'second']);
        $this->relay->expects('claim')->with('first', 100)->andReturn([]);
        $this->relay->expects('claim')->with('second', 100)->andReturn([]);
        Sleep::whenFakingSleep(function (): void { ($this->signalHandler)(SIGTERM); });

        $this->assertSame(0, $worker->run(null, new OutboxWorkerOptions(sleep: 0.25, memory: 1024)));
        Sleep::assertSequence([Sleep::usleep(250_000)]);
    }

    public function testPauseStopsAdmissionUntilTheResumeSignal(): void
    {
        Sleep::fake();
        $worker = $this->worker();
        $claims = 0;
        $sleeps = 0;
        $this->relay->shouldReceive('claim')->twice()->andReturnUsing(function () use (&$claims): array {
            return ++$claims === 1 ? [] : [$this->message(1)];
        });
        $this->relay->expects('deliver')->andReturn(true);
        Sleep::whenFakingSleep(function () use (&$claims, &$sleeps): void {
            $this->assertSame(1, $claims);
            ($this->signalHandler)(++$sleeps === 1 ? SIGUSR2 : SIGCONT);
        });

        $this->assertSame(0, $worker->run(null, new OutboxWorkerOptions(sleep: 0.25, maxJobs: 1, memory: 1024)));
        Sleep::assertSequence([Sleep::usleep(250_000), Sleep::usleep(250_000)]);
    }

    public function testRestartReadsEndTheirCoroutineBeforeClaimingAndRestartStopsAdmission(): void
    {
        $worker = $this->worker();
        CoroutineContext::set('request-only', true);
        $borrowed = false;
        $reads = 0;
        $this->cache->shouldReceive('get')->with(Worker::RESTART_SIGNAL_CACHE_KEY)->times(3)->andReturnUsing(function () use (&$borrowed, &$reads): int {
            $this->assertFalse(CoroutineContext::has('request-only'));
            $borrowed = true;
            Coroutine::defer(static function () use (&$borrowed): void { $borrowed = false; });

            return ++$reads === 3 ? 2 : 1;
        });
        $this->relay->expects('claim')->andReturnUsing(function () use (&$borrowed): array {
            $this->assertFalse($borrowed);

            return [$this->message(1)];
        });
        $this->relay->expects('deliver')->andReturn(true);

        $this->assertSame(0, $worker->run(null, new OutboxWorkerOptions(memory: 1024)));
        $this->assertFalse($borrowed);
    }

    public function testEscapingTaskFailureStopsAdmissionAndPropagates(): void
    {
        $worker = $this->worker();
        $failure = new RuntimeException('Reporter unavailable.');
        $this->relay->expects('claim')->andReturn([$this->message(1)]);
        $this->relay->expects('deliver')->andThrow($failure);

        try {
            $worker->run(null, new OutboxWorkerOptions(memory: 1024));
            $this->fail('Task failure must reach the command.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testCancellationJoinsTaskCleanupBeforeReturningToTheCaller(): void
    {
        $worker = $this->worker();
        $started = new Channel(1);
        $blocker = new Channel(1);
        $released = false;
        $caught = null;
        $this->relay->expects('claim')->andReturn([$this->message(1)]);
        $this->relay->expects('deliver')->andReturnUsing(static function () use ($started, $blocker, &$released): bool {
            Coroutine::defer(static function () use (&$released): void { $released = true; });
            $started->push(true);
            $blocker->pop(5);

            return true;
        });
        $coordinator = Coroutine::create(static function () use ($worker, &$caught): void {
            try {
                $worker->run(null, new OutboxWorkerOptions(maxJobs: 1, memory: 1024));
            } catch (CanceledException $exception) {
                $caught = $exception;
            }
        });

        try {
            $this->assertTrue($started->pop(2));
            EngineCoroutine::cancelById($coordinator, throwException: true);
            Coroutine::join([$coordinator], 2);
            $this->assertFalse(Coroutine::exists($coordinator));
            $this->assertInstanceOf(CanceledException::class, $caught);
            $this->assertTrue($released);
        } finally {
            $blocker->close();
            Coroutine::join([$coordinator], 5);
        }
    }

    public function testMemoryLimitStopsBeforeClaiming(): void
    {
        $this->assertSame(Worker::EXIT_MEMORY_LIMIT, $this->worker()->run(null, new OutboxWorkerOptions(memory: 1)));
    }

    /**
     * Construct an isolated worker with observable dependencies and signal ownership.
     */
    protected function worker(array $databases = ['primary']): OutboxWorker
    {
        $repository = m::mock(DatabaseOutboxRepository::class);
        $repository->shouldReceive('getDatabases')->andReturn($databases);
        $this->relay = m::mock(OutboxRelay::class);
        $this->exceptions = m::mock(ExceptionHandler::class);
        $this->cache = m::mock(Cache::class);
        $this->cache->shouldReceive('get')->with(Worker::RESTART_SIGNAL_CACHE_KEY)->andReturn(null)->byDefault();
        $cacheFactory = m::mock(CacheFactory::class);
        $cacheFactory->shouldReceive('store')->andReturn($this->cache);
        $signals = m::mock(SignalRegistry::class);
        $signals->expects('register')->andReturnUsing(function (object $owner, array $signals, Closure $callback): void {
            $this->assertSame($owner, $callback);
            $this->signalHandler = $callback;
        });
        $signals->expects('unregister')->with(m::on(fn (object $owner): bool => $owner === $this->signalHandler));

        return new OutboxWorker($repository, $this->relay, $cacheFactory, $this->exceptions, $signals);
    }

    /**
     * Create a claimed message for worker admission tests.
     */
    protected function message(int $id): OutboxMessage
    {
        return new OutboxMessage($id, "job-{$id}", 'lease', 1, 'redis', 'default', '{}', [], null, 1, 1);
    }
}
