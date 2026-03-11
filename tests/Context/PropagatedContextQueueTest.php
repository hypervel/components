<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\Context;
use Hypervel\Context\PropagatedContext;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Queue;
use Hypervel\Queue\Queueable;
use Hypervel\Queue\SyncQueue;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class PropagatedContextQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Context::flush();
    }

    protected function tearDown(): void
    {
        Context::flush();
        Queue::createPayloadUsing(null);

        parent::tearDown();
    }

    public function testPropagatedContextIsIncludedInJobPayload()
    {
        Context::propagated()->add('trace_id', 'abc-123');

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertArrayHasKey('hypervel:context', $payload);
        $this->assertArrayHasKey('data', $payload['hypervel:context']);
        $this->assertArrayHasKey('trace_id', $payload['hypervel:context']['data']);
    }

    public function testEmptyPropagatedContextDoesNotAddToPayload()
    {
        // Access propagated context but don't add anything
        Context::propagated();

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertArrayNotHasKey('hypervel:context', $payload);
    }

    public function testPayloadHookSkipsWhenNoPropagatedContextExists()
    {
        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertArrayNotHasKey('hypervel:context', $payload);
        $this->assertFalse(Context::hasPropagated());
    }

    public function testHiddenContextIsIncludedInJobPayload()
    {
        Context::propagated()->addHidden('api_key', 'secret-token');

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertArrayHasKey('hypervel:context', $payload);
        $this->assertArrayHasKey('hidden', $payload['hypervel:context']);
        $this->assertArrayHasKey('api_key', $payload['hypervel:context']['hidden']);
    }

    public function testPropagatedContextIsHydratedWhenJobProcesses()
    {
        // Build a payload with context
        Context::propagated()->add('trace_id', 'abc-123');
        Context::propagated()->addHidden('secret', 'token');

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        // Clear context to simulate a fresh coroutine
        Context::flush();
        $this->assertFalse(Context::hasPropagated());

        // Simulate JobProcessing event with the payload
        $job = m::mock(\Hypervel\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);

        $event = new JobProcessing('sync', $job);
        $this->app['events']->dispatch($event);

        // Context should now be hydrated
        $this->assertSame('abc-123', Context::propagated()->get('trace_id'));
        $this->assertSame('token', Context::propagated()->getHidden('secret'));
    }

    public function testHydrateSkipsWhenPayloadHasNoContext()
    {
        $job = m::mock(\Hypervel\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn(['job' => 'SomeJob']);

        $event = new JobProcessing('sync', $job);
        $this->app['events']->dispatch($event);

        // No PropagatedContext should have been allocated
        $this->assertFalse(Context::hasPropagated());
    }

    public function testDehydratingHookFiresBeforeJobDispatch()
    {
        $called = false;

        Context::propagated()->add('trace_id', 'abc-123');
        Context::propagated()->dehydrating(function (PropagatedContext $context) use (&$called) {
            $called = true;
            // Callback can modify context before serialization
            $context->add('dehydrated_at', 'test-timestamp');
        });

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        $this->assertTrue($called);
        // The dehydrating callback's addition should be in the payload
        $this->assertArrayHasKey('dehydrated_at', $payload['hypervel:context']['data']);
    }

    public function testHydratedHookFiresWhenJobProcesses()
    {
        $called = false;

        // Build a payload with context
        Context::propagated()->add('trace_id', 'abc-123');
        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        // Clear and set up hydrated callback
        Context::flush();
        Context::propagated()->hydrated(function (PropagatedContext $context) use (&$called) {
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
        Context::propagated()->add('trace_id', 'abc-123');
        Context::propagated()->dehydrating(function (PropagatedContext $context) {
            $context->add('extra', 'injected');
            $context->forget('trace_id');
        });

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        // Payload should reflect the dehydrating callback's modifications
        $this->assertArrayHasKey('extra', $payload['hypervel:context']['data']);
        $this->assertArrayNotHasKey('trace_id', $payload['hypervel:context']['data']);

        // Original propagated context should be untouched
        $this->assertSame('abc-123', Context::propagated()->get('trace_id'));
        $this->assertNull(Context::propagated()->get('extra'));
    }

    public function testRoundTripPreservesVariousDataTypes()
    {
        Context::propagated()->add('string', 'hello');
        Context::propagated()->add('integer', 42);
        Context::propagated()->add('float', 3.14);
        Context::propagated()->add('bool_true', true);
        Context::propagated()->add('bool_false', false);
        Context::propagated()->add('null_value', null);
        Context::propagated()->add('array', ['nested' => ['deep' => true]]);
        Context::propagated()->addHidden('secret', 'hidden-value');

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload('SomeJob', null);

        // Clear context to simulate fresh coroutine
        Context::flush();

        // Hydrate from the payload
        $job = m::mock(\Hypervel\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $this->app['events']->dispatch(new JobProcessing('sync', $job));

        // Verify all types survived the round trip
        $this->assertSame('hello', Context::propagated()->get('string'));
        $this->assertSame(42, Context::propagated()->get('integer'));
        $this->assertSame(3.14, Context::propagated()->get('float'));
        $this->assertTrue(Context::propagated()->get('bool_true'));
        $this->assertFalse(Context::propagated()->get('bool_false'));
        $this->assertNull(Context::propagated()->get('null_value'));
        $this->assertTrue(Context::propagated()->has('null_value'));
        $this->assertSame(['nested' => ['deep' => true]], Context::propagated()->get('array'));
        $this->assertSame('hidden-value', Context::propagated()->getHidden('secret'));
    }

    public function testEndToEndSyncJobReceivesPropagatedContext()
    {
        PropagatedContextQueueTestJob::$receivedTraceId = null;
        PropagatedContextQueueTestJob::$receivedSecret = null;

        Context::propagated()->add('trace_id', 'e2e-test-123');
        Context::propagated()->addHidden('secret', 'e2e-secret');

        $queue = $this->createSyncQueue();
        $queue->push(new PropagatedContextQueueTestJob());

        $this->assertSame('e2e-test-123', PropagatedContextQueueTestJob::$receivedTraceId);
        $this->assertSame('e2e-secret', PropagatedContextQueueTestJob::$receivedSecret);
    }

    /**
     * Create a SyncQueue for payload testing.
     */
    protected function createSyncQueue(): TestableSyncQueue
    {
        $queue = new TestableSyncQueue();
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
class PropagatedContextQueueTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static ?string $receivedTraceId = null;

    public static ?string $receivedSecret = null;

    public function handle(): void
    {
        static::$receivedTraceId = Context::propagated()->get('trace_id');
        static::$receivedSecret = Context::propagated()->getHidden('secret');
    }
}
