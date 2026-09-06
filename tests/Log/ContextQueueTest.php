<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Hypervel\Bus\DispatchLockContext;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Queue\Queueable;
use Hypervel\Log\Context\Repository;
use Hypervel\Queue\BackgroundQueue;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Queue;
use Hypervel\Queue\SyncQueue;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;

class ContextQueueTest extends TestCase
{
    public function testContextIsIncludedInJobPayload(): void
    {
        Repository::getInstance()->add('trace_id', 'abc-123');

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertArrayHasKey('illuminate:log:context', $payload);
        $this->assertArrayHasKey('data', $payload['illuminate:log:context']);
        $this->assertArrayHasKey('trace_id', $payload['illuminate:log:context']['data']);
    }

    public function testEmptyContextDoesNotAddToPayload(): void
    {
        // Access context but don't add anything
        Repository::getInstance();

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertArrayNotHasKey('illuminate:log:context', $payload);
    }

    public function testPayloadHookSkipsWhenNoContextExists(): void
    {
        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertArrayNotHasKey('illuminate:log:context', $payload);
        $this->assertFalse(Repository::hasInstance());
    }

    public function testHiddenContextIsIncludedInJobPayload(): void
    {
        Repository::getInstance()->addHidden('api_key', 'secret-token');

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertArrayHasKey('illuminate:log:context', $payload);
        $this->assertArrayHasKey('hidden', $payload['illuminate:log:context']);
        $this->assertArrayHasKey('api_key', $payload['illuminate:log:context']['hidden']);
    }

    public function testUniqueJobMetadataIsScopedToItsPayloadWithoutReplacingExistingContext(): void
    {
        $context = Repository::getInstance()
            ->add('trace_id', 'abc-123')
            ->addHidden('persistent', 'value');

        $job = new ContextQueueUniqueJob('unique-id');
        $this->acquireUniqueJob($job);

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload($job, null);

        $this->assertSame('unique', unserialize($payload['illuminate:log:context']['hidden']['laravel_unique_job_cache_store']));
        $this->assertSame(
            'laravel_unique_job:' . ContextQueueUniqueJob::class . ':unique-id',
            unserialize($payload['illuminate:log:context']['hidden']['laravel_unique_job_key'])
        );
        $this->assertNotSame('', unserialize($payload['illuminate:log:context']['hidden']['laravel_unique_job_lock_owner']));
        $this->assertSame('value', unserialize($payload['illuminate:log:context']['hidden']['persistent']));
        $this->assertSame('abc-123', $context->get('trace_id'));
        $this->assertSame(['persistent' => 'value'], $context->allHidden());
        $this->assertNotNull(DispatchLockContext::peekPayloadMetadata($job));
    }

    public function testLaterPayloadHookCanComposeWithContextAndLiveJobMetadata(): void
    {
        Repository::getInstance()
            ->add('trace_id', 'original')
            ->add('request_id', 'request-123')
            ->addHidden('persistent', 'hidden-value');

        $job = new ContextQueueUniqueJob('composed-payload');
        $this->acquireUniqueJob($job);

        $observedCommandName = null;
        $observedCommand = null;

        Queue::createPayloadUsing(function (
            string $connection,
            ?string $queue,
            array $payload,
        ) use (&$observedCommandName, &$observedCommand): array {
            $observedCommandName = $payload['data']['commandName'];
            $observedCommand = $payload['data']['command'];
            $context = $payload['illuminate:log:context'];
            $context['data']['trace_id'] = serialize('updated');

            return ['illuminate:log:context' => $context];
        });

        $payload = $this->createSyncQueue()->testCreatePayload($job, null);
        $context = $payload['illuminate:log:context'];

        $this->assertSame($job, $observedCommandName);
        $this->assertSame($job, $observedCommand);
        $this->assertSame('updated', unserialize($context['data']['trace_id']));
        $this->assertSame('request-123', unserialize($context['data']['request_id']));
        $this->assertSame('hidden-value', unserialize($context['hidden']['persistent']));
        $this->assertSame('unique', unserialize($context['hidden']['laravel_unique_job_cache_store']));
        $this->assertSame(
            'laravel_unique_job:' . ContextQueueUniqueJob::class . ':composed-payload',
            unserialize($context['hidden']['laravel_unique_job_key']),
        );
        $this->assertNotSame('', unserialize($context['hidden']['laravel_unique_job_lock_owner']));
        $this->assertSame(ContextQueueUniqueJob::class, $payload['data']['commandName']);
        $this->assertInstanceOf(ContextQueueUniqueJob::class, unserialize($payload['data']['command']));
        $this->assertNotNull(DispatchLockContext::peekPayloadMetadata($job));
    }

    public function testUniqueJobMetadataScopeIsRestoredWhenAPayloadHookThrows(): void
    {
        $context = Repository::getInstance()->addHidden('persistent', 'value');
        $job = new ContextQueueUniqueJob('failing-payload');
        $this->acquireUniqueJob($job);

        $throw = true;
        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload) use (&$throw): array {
            if (($payload['data']['commandName'] ?? null) instanceof ContextQueueUniqueJob && $throw) {
                throw new RuntimeException('Payload hook failed.');
            }

            return [];
        });

        $queue = $this->createSyncQueue();

        try {
            $queue->testCreatePayload($job, null);
            $this->fail('The payload hook should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payload hook failed.', $exception->getMessage());
        }

        $this->assertSame(['persistent' => 'value'], $context->allHidden());
        $this->assertNotNull(DispatchLockContext::peekPayloadMetadata($job));

        DispatchLockContext::release($job);

        $this->assertNull(DispatchLockContext::peekPayloadMetadata($job));

        $throw = false;
        $payload = $queue->testCreatePayload(new ContextQueueTestJob, null);

        $this->assertArrayNotHasKey(
            'laravel_unique_job_cache_store',
            $payload['illuminate:log:context']['hidden']
        );
        $this->assertArrayNotHasKey(
            'laravel_unique_job_key',
            $payload['illuminate:log:context']['hidden']
        );
    }

    public function testContextIsHydratedWhenJobProcesses(): void
    {
        // Build a payload with context
        Repository::getInstance()->add('trace_id', 'abc-123');
        Repository::getInstance()->addHidden('secret', 'token');

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        // Clear context to simulate a fresh coroutine
        CoroutineContext::flush();
        $this->assertFalse(Repository::hasInstance());

        // Simulate JobProcessing event with the payload
        $job = m::mock(\Hypervel\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);

        $event = new JobProcessing('sync', $job);
        $this->app->make('events')->dispatch($event);

        // Context should now be hydrated
        $this->assertSame('abc-123', Repository::getInstance()->get('trace_id'));
        $this->assertSame('token', Repository::getInstance()->getHidden('secret'));
    }

    public function testHydrateSkipsWhenPayloadHasNoContext(): void
    {
        $job = m::mock(\Hypervel\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn(['job' => 'SomeJob']);

        $event = new JobProcessing('sync', $job);
        $this->app->make('events')->dispatch($event);

        // No context Repository should have been allocated
        $this->assertFalse(Repository::hasInstance());
    }

    public function testPayloadWithoutContextFlushesAnExistingRepository(): void
    {
        $repository = Repository::getInstance();
        $repository->add('stale', 'value');
        $repository->addHidden('secret', 'value');

        $job = m::mock(\Hypervel\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn(['job' => 'SomeJob']);

        $this->app->make('events')->dispatch(new JobProcessing('sync', $job));

        $this->assertSame($repository, Repository::getInstance());
        $this->assertSame([], $repository->all());
        $this->assertSame([], $repository->allHidden());
    }

    public function testDehydratingHookFiresBeforeJobDispatch(): void
    {
        $called = false;

        Repository::getInstance()->add('trace_id', 'abc-123');
        Repository::getInstance()->dehydrating(function (Repository $context) use (&$called) {
            $called = true;
            // Callback can modify context before serialization
            $context->add('dehydrated_at', 'test-timestamp');
        });

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertTrue($called);
        // The dehydrating callback's addition should be in the payload
        $this->assertArrayHasKey('dehydrated_at', $payload['illuminate:log:context']['data']);
    }

    public function testDehydratingHookContributesContextInAFreshCoroutine(): void
    {
        Repository::getInstance()->dehydrating(static function (Repository $context): void {
            $context->addHidden('locale', 'en');
        });

        $queue = $this->createSyncQueue();
        $result = new Channel(1);

        Coroutine::create(static function () use ($queue, $result): void {
            $result->push($queue->testCreatePayload('SomeJob', null));
        });

        $payload = $result->pop(1);

        $this->assertSame(serialize('en'), $payload['illuminate:log:context']['hidden']['locale']);
    }

    public function testHydratedHookFiresWhenJobProcesses(): void
    {
        $called = false;

        // Build a payload with context
        Repository::getInstance()->add('trace_id', 'abc-123');
        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        // Clear and set up hydrated callback
        CoroutineContext::flush();
        Repository::getInstance()->hydrated(function (Repository $context) use (&$called) {
            $called = true;
        });

        // Simulate JobProcessing
        $job = m::mock(\Hypervel\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);

        $this->app->make('events')->dispatch(new JobProcessing('sync', $job));

        $this->assertTrue($called);
    }

    public function testDehydratingCallbackCanModifyWithoutAffectingOriginal(): void
    {
        Repository::getInstance()->add('trace_id', 'abc-123');
        Repository::getInstance()->dehydrating(function (Repository $context) {
            $context->add('extra', 'injected');
            $context->forget('trace_id');
        });

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        // Payload should reflect the dehydrating callback's modifications
        $this->assertArrayHasKey('extra', $payload['illuminate:log:context']['data']);
        $this->assertArrayNotHasKey('trace_id', $payload['illuminate:log:context']['data']);

        // Original context should be untouched
        $this->assertSame('abc-123', Repository::getInstance()->get('trace_id'));
        $this->assertNull(Repository::getInstance()->get('extra'));
    }

    public function testRoundTripPreservesVariousDataTypes(): void
    {
        Repository::getInstance()->add('string', 'hello');
        Repository::getInstance()->add('integer', 42);
        Repository::getInstance()->add('float', 3.14);
        Repository::getInstance()->add('bool_true', true);
        Repository::getInstance()->add('bool_false', false);
        Repository::getInstance()->add('null_value', null);
        Repository::getInstance()->add('array', ['nested' => ['deep' => true]]);
        Repository::getInstance()->addHidden('secret', 'hidden-value');

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        // Clear context to simulate fresh coroutine
        CoroutineContext::flush();

        // Hydrate from the payload
        $job = m::mock(\Hypervel\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $this->app->make('events')->dispatch(new JobProcessing('sync', $job));

        // Verify all types survived the round trip
        $this->assertSame('hello', Repository::getInstance()->get('string'));
        $this->assertSame(42, Repository::getInstance()->get('integer'));
        $this->assertSame(3.14, Repository::getInstance()->get('float'));
        $this->assertTrue(Repository::getInstance()->get('bool_true'));
        $this->assertFalse(Repository::getInstance()->get('bool_false'));
        $this->assertNull(Repository::getInstance()->get('null_value'));
        $this->assertTrue(Repository::getInstance()->has('null_value'));
        $this->assertSame(['nested' => ['deep' => true]], Repository::getInstance()->get('array'));
        $this->assertSame('hidden-value', Repository::getInstance()->getHidden('secret'));
    }

    public function testEndToEndSyncJobReceivesContext(): void
    {
        ContextQueueTestJob::$receivedTraceId = null;
        ContextQueueTestJob::$receivedSecret = null;

        Repository::getInstance()->add('trace_id', 'e2e-test-123');
        Repository::getInstance()->addHidden('secret', 'e2e-secret');

        $queue = $this->createSyncQueue();
        $queue->push(new ContextQueueTestJob);

        $this->assertSame('e2e-test-123', ContextQueueTestJob::$receivedTraceId);
        $this->assertSame('e2e-secret', ContextQueueTestJob::$receivedSecret);
    }

    public function testBackgroundJobPayloadCapturesParentLogContextBeforeSpawning(): void
    {
        ContextQueueBackgroundJob::$receivedTraceId = null;
        ContextQueueBackgroundJob::$completed = new Channel(1);
        Repository::getInstance()->add('trace_id', 'parent-context');

        $queue = new BackgroundQueue;
        $queue->setContainer($this->app);
        $queue->setConnectionName('background');
        $queue->push(new ContextQueueBackgroundJob);

        $this->assertTrue(ContextQueueBackgroundJob::$completed->pop(1));
        $this->assertSame('parent-context', ContextQueueBackgroundJob::$receivedTraceId);

        ContextQueueBackgroundJob::$completed = null;
    }

    /**
     * Create a SyncQueue for payload testing.
     */
    protected function createSyncQueue(): TestableSyncQueue
    {
        $queue = new TestableSyncQueue;
        $queue->setContainer($this->app);
        $queue->setConnectionName('sync');

        return $queue;
    }

    /**
     * Acquire the unique lock owned by a test dispatch.
     */
    private function acquireUniqueJob(ContextQueueUniqueJob $job): void
    {
        $this->assertTrue(
            (new UniqueLock(new CacheRepository(new WorkerArrayStore)))->acquireForDispatch($job)
        );
    }
}

/**
 * Expose createPayloadArray for testing.
 */
class TestableSyncQueue extends SyncQueue
{
    public function testCreatePayload(object|string $job, ?string $queue): array
    {
        return $this->createPayloadArray($job, $queue);
    }
}

class ContextQueueTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static ?string $receivedTraceId = null;

    public static ?string $receivedSecret = null;

    public function handle(): void
    {
        static::$receivedTraceId = Repository::getInstance()->get('trace_id');
        static::$receivedSecret = Repository::getInstance()->getHidden('secret');
    }
}

class ContextQueueBackgroundJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static ?Channel $completed = null;

    public static ?string $receivedTraceId = null;

    public function handle(): void
    {
        static::$receivedTraceId = Repository::getInstance()->get('trace_id');
        static::$completed?->push(true);
    }
}

class ContextQueueUniqueJob implements ShouldBeUnique
{
    public function __construct(
        public string $id
    ) {
    }

    public function uniqueId(): string
    {
        return $this->id;
    }

    public function uniqueVia(): CacheRepository
    {
        return new CacheRepository(new WorkerArrayStore, ['store' => 'unique']);
    }
}
