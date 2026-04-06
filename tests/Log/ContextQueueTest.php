<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Queue\Queueable;
use Hypervel\Log\Context\Repository;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SyncQueue;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class ContextQueueTest extends TestCase
{
    public function testContextIsIncludedInJobPayload()
    {
        Repository::getInstance()->add('trace_id', 'abc-123');

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertArrayHasKey('illuminate:log:context', $payload);
        $this->assertArrayHasKey('data', $payload['illuminate:log:context']);
        $this->assertArrayHasKey('trace_id', $payload['illuminate:log:context']['data']);
    }

    public function testEmptyContextDoesNotAddToPayload()
    {
        // Access context but don't add anything
        Repository::getInstance();

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertArrayNotHasKey('illuminate:log:context', $payload);
    }

    public function testPayloadHookSkipsWhenNoContextExists()
    {
        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertArrayNotHasKey('illuminate:log:context', $payload);
        $this->assertFalse(Repository::hasInstance());
    }

    public function testHiddenContextIsIncludedInJobPayload()
    {
        Repository::getInstance()->addHidden('api_key', 'secret-token');

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertArrayHasKey('illuminate:log:context', $payload);
        $this->assertArrayHasKey('hidden', $payload['illuminate:log:context']);
        $this->assertArrayHasKey('api_key', $payload['illuminate:log:context']['hidden']);
    }

    public function testContextIsHydratedWhenJobProcesses()
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
        $this->app['events']->dispatch($event);

        // Context should now be hydrated
        $this->assertSame('abc-123', Repository::getInstance()->get('trace_id'));
        $this->assertSame('token', Repository::getInstance()->getHidden('secret'));
    }

    public function testHydrateSkipsWhenPayloadHasNoContext()
    {
        $job = m::mock(\Hypervel\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn(['job' => 'SomeJob']);

        $event = new JobProcessing('sync', $job);
        $this->app['events']->dispatch($event);

        // No context Repository should have been allocated
        $this->assertFalse(Repository::hasInstance());
    }

    public function testDehydratingHookFiresBeforeJobDispatch()
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

    public function testHydratedHookFiresWhenJobProcesses()
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

        $this->app['events']->dispatch(new JobProcessing('sync', $job));

        $this->assertTrue($called);
    }

    public function testDehydratingCallbackCanModifyWithoutAffectingOriginal()
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

    public function testRoundTripPreservesVariousDataTypes()
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
        $this->app['events']->dispatch(new JobProcessing('sync', $job));

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

    public function testEndToEndSyncJobReceivesContext()
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
}

/**
 * Expose createPayloadArray for testing.
 *
 * @internal
 */
class TestableSyncQueue extends SyncQueue
{
    public function testCreatePayload(string $job, ?string $queue): array
    {
        return $this->createPayloadArray($job, $queue);
    }
}

/**
 * @internal
 */
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
