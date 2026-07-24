<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\JobDispatchingTest;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Queue;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Config;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Queue\QueueTestCase;
use RuntimeException;

#[WithMigration]
#[WithMigration('queue')]
class JobDispatchingTest extends QueueTestCase
{
    protected function setUp(): void
    {
        $this->beforeApplicationDestroyed(function () {
            Job::$ran = false;
            Job::$value = null;
        });

        parent::setUp();
    }

    public function testJobCanUseCustomMethodsAfterDispatch(): void
    {
        Job::dispatch('test')->replaceValue('new-test');

        $this->runQueueWorkerCommand(['--stop-when-empty' => true]);

        $this->assertTrue(Job::$ran);
        $this->assertSame('new-test', Job::$value);
    }

    public function testDispatchesConditionallyWithBoolean(): void
    {
        Job::dispatchIf(false, 'test')->replaceValue('new-test');

        $this->runQueueWorkerCommand(['--stop-when-empty' => true]);

        $this->assertFalse(Job::$ran);
        $this->assertNull(Job::$value);

        Job::dispatchIf(true, 'test')->replaceValue('new-test');

        $this->runQueueWorkerCommand(['--stop-when-empty' => true]);

        $this->assertTrue(Job::$ran);
        $this->assertSame('new-test', Job::$value);
    }

    public function testDispatchesConditionallyWithClosure(): void
    {
        Job::dispatchIf(fn ($job) => $job instanceof Job ? 0 : 1, 'test')->replaceValue('new-test');

        $this->runQueueWorkerCommand(['--stop-when-empty' => true]);

        $this->assertFalse(Job::$ran);

        Job::dispatchIf(fn ($job) => $job instanceof Job ? 1 : 0, 'test')->replaceValue('new-test');

        $this->runQueueWorkerCommand(['--stop-when-empty' => true]);

        $this->assertTrue(Job::$ran);
    }

    public function testDoesNotDispatchConditionallyWithBoolean(): void
    {
        Job::dispatchUnless(true, 'test')->replaceValue('new-test');

        $this->runQueueWorkerCommand(['--stop-when-empty' => true]);

        $this->assertFalse(Job::$ran);
        $this->assertNull(Job::$value);

        Job::dispatchUnless(false, 'test')->replaceValue('new-test');

        $this->runQueueWorkerCommand(['--stop-when-empty' => true]);

        $this->assertTrue(Job::$ran);
        $this->assertSame('new-test', Job::$value);
    }

    public function testDoesNotDispatchConditionallyWithClosure(): void
    {
        Job::dispatchUnless(fn ($job) => $job instanceof Job ? 1 : 0, 'test')->replaceValue('new-test');

        $this->runQueueWorkerCommand(['--stop-when-empty' => true]);

        $this->assertFalse(Job::$ran);

        Job::dispatchUnless(fn ($job) => $job instanceof Job ? 0 : 1, 'test')->replaceValue('new-test');

        $this->runQueueWorkerCommand(['--stop-when-empty' => true]);

        $this->assertTrue(Job::$ran);
    }

    public function testUniqueJobLockIsReleasedForJobDispatchedAfterResponse(): void
    {
        // Use worker-array because unique locks must be visible across the after-response child coroutine.
        Config::set('cache.default', 'worker-array');

        $lockHeldBeforeCoroutineExit = false;

        $this->dispatchAfterResponseInChildCoroutine(function () use (&$lockHeldBeforeCoroutineExit) {
            UniqueJob::dispatchAfterResponse('test');

            $lockHeldBeforeCoroutineExit = ! $this->getJobLock(UniqueJob::class, 'test');
        });

        $this->assertTrue($lockHeldBeforeCoroutineExit);
        $this->assertTrue(UniqueJob::$ran);

        UniqueJob::$ran = false;
        $this->dispatchAfterResponseInChildCoroutine(function () {
            UniqueJob::dispatch('test')->afterResponse();
        });
        $this->assertTrue(UniqueJob::$ran);

        // acquire job lock and confirm that job is not dispatched after response
        $this->assertTrue(
            $this->getJobLock(UniqueJob::class, 'test')
        );

        UniqueJob::$ran = false;
        $this->dispatchAfterResponseInChildCoroutine(function () {
            UniqueJob::dispatch('test')->afterResponse();
        });
        $this->assertFalse(UniqueJob::$ran);

        // confirm that dispatchAfterResponse also does not run
        $this->dispatchAfterResponseInChildCoroutine(function () {
            UniqueJob::dispatchAfterResponse('test');
        });
        $this->assertFalse(UniqueJob::$ran);
    }

    public function testUniqueJobMetadataIsIncludedWhenPayloadIsCreatedAfterResponse(): void
    {
        Config::set('cache.default', 'worker-array');

        $payload = null;
        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $currentPayload) use (&$payload): array {
            if (($currentPayload['data']['commandName'] ?? null) instanceof UniqueJob) {
                $payload = $currentPayload;
            }

            return [];
        });

        $this->dispatchAfterResponseInChildCoroutine(function (): void {
            UniqueJob::dispatchAfterResponse('after-response-metadata');
        });

        $this->assertSame(
            'worker-array',
            unserialize($payload['illuminate:log:context']['hidden']['laravel_unique_job_cache_store'])
        );
        $this->assertSame(
            'laravel_unique_job:' . UniqueJob::class . ':after-response-metadata',
            unserialize($payload['illuminate:log:context']['hidden']['laravel_unique_job_key'])
        );
    }

    public function testPayloadHookFailureDoesNotLeakUniqueMetadataIntoTheNextPayload(): void
    {
        Config::set('cache.default', 'worker-array');
        ContextRepository::getInstance()->addHidden('persistent', 'value');

        $throw = true;
        $ordinaryPayload = null;

        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload) use (&$ordinaryPayload, &$throw): array {
            if (($payload['data']['commandName'] ?? null) instanceof UniqueJob && $throw) {
                throw new RuntimeException('Payload hook failed.');
            }

            if (($payload['data']['commandName'] ?? null) instanceof Job) {
                $ordinaryPayload = $payload;
            }

            return [];
        });

        try {
            UniqueJob::dispatch('payload-failure');
            $this->fail('The payload hook should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payload hook failed.', $exception->getMessage());
        }

        $this->assertSame(['persistent' => 'value'], ContextRepository::getInstance()->allHidden());

        $throw = false;
        Job::dispatch('ordinary');

        $this->assertArrayNotHasKey(
            'laravel_unique_job_cache_store',
            $ordinaryPayload['illuminate:log:context']['hidden']
        );
        $this->assertArrayNotHasKey(
            'laravel_unique_job_key',
            $ordinaryPayload['illuminate:log:context']['hidden']
        );
        $this->assertSame(
            'value',
            unserialize($ordinaryPayload['illuminate:log:context']['hidden']['persistent'])
        );
    }

    public function testQueueMayBeNullForJobQueueingAndJobQueuedEvent(): void
    {
        Config::set('queue.default', 'database');
        $events = [];
        $this->app['events']->listen(function (JobQueueing $e) use (&$events) {
            $events[] = $e;
        });
        $this->app['events']->listen(function (JobQueued $e) use (&$events) {
            $events[] = $e;
        });

        MyTestDispatchableJob::dispatch();
        dispatch(function () {
        });

        $this->assertCount(4, $events);
        $this->assertInstanceOf(JobQueueing::class, $events[0]);
        $this->assertNull($events[0]->queue);
        $this->assertInstanceOf(JobQueued::class, $events[1]);
        $this->assertNull($events[1]->queue);
        $this->assertInstanceOf(JobQueueing::class, $events[2]);
        $this->assertNull($events[2]->queue);
        $this->assertInstanceOf(JobQueued::class, $events[3]);
        $this->assertNull($events[3]->queue);
    }

    public function testQueuedClosureCanBeNamed(): void
    {
        Config::set('queue.default', 'database');
        $events = [];
        $this->app['events']->listen(function (JobQueued $e) use (&$events) {
            $events[] = $e;
        });

        dispatch(function () {
        })->name('custom name');

        $this->assertCount(1, $events);
        $this->assertInstanceOf(JobQueued::class, $events[0]);
        $this->assertSame('custom name', $events[0]->job->name);
        $this->assertStringContainsString('custom name', $events[0]->job->displayName());
    }

    public function testCanDisableDispatchingAfterResponse(): void
    {
        $ranBeforeCoroutineExit = false;

        $this->dispatchAfterResponseInChildCoroutine(function () use (&$ranBeforeCoroutineExit) {
            Job::dispatchAfterResponse('test');

            $ranBeforeCoroutineExit = Job::$ran;
        });

        $this->assertFalse($ranBeforeCoroutineExit);
        $this->assertTrue(Job::$ran);

        Bus::withoutDispatchingAfterResponses();

        Job::$ran = false;
        $ranBeforeCoroutineExit = false;

        $this->dispatchAfterResponseInChildCoroutine(function () use (&$ranBeforeCoroutineExit) {
            Job::dispatchAfterResponse('test');

            $ranBeforeCoroutineExit = Job::$ran;
        });

        $this->assertTrue($ranBeforeCoroutineExit);
        $this->assertTrue(Job::$ran);

        Bus::withDispatchingAfterResponses();

        Job::$ran = false;
        $ranBeforeCoroutineExit = false;

        $this->dispatchAfterResponseInChildCoroutine(function () use (&$ranBeforeCoroutineExit) {
            Job::dispatchAfterResponse('test');

            $ranBeforeCoroutineExit = Job::$ran;
        });

        $this->assertFalse($ranBeforeCoroutineExit);
        $this->assertTrue(Job::$ran);
    }

    /**
     * Helpers.
     */
    private function getJobLock(string $job, mixed $value = null): bool
    {
        return $this->app->get(Repository::class)->lock('laravel_unique_job:' . $job . ':' . $value, 10)->get();
    }

    private function dispatchAfterResponseInChildCoroutine(callable $callback): void
    {
        $channel = new Channel(2);

        Coroutine::create(function () use ($callback, $channel) {
            Coroutine::defer(function () use ($channel) {
                $channel->push('coroutine-exited');
            });

            $callback();

            $channel->push('callback-returned');
        });

        $this->assertSame('callback-returned', $channel->pop());
        $this->assertSame('coroutine-exited', $channel->pop());
    }
}

class Job implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static bool $ran = false;

    public static ?string $usedQueue = null;

    public static ?string $usedConnection = null;

    public static ?string $value = null;

    public function __construct(?string $value)
    {
        static::$value = $value;
    }

    public function handle(): void
    {
        static::$ran = true;
    }

    public function replaceValue(?string $value): void
    {
        static::$value = $value;
    }
}

class UniqueJob extends Job implements ShouldBeUnique
{
    use InteractsWithQueue;

    public function uniqueId(): ?string
    {
        return self::$value;
    }
}

class MyTestDispatchableJob implements ShouldQueue
{
    use Dispatchable;
}
