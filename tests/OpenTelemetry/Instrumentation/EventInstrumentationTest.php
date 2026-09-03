<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Hypervel\Events\Dispatcher;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Instrumentation\EventInstrumentation;
use Hypervel\Tests\TestCase;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use RuntimeException;

class EventInstrumentationTest extends TestCase
{
    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Dispatcher $events;

    private InMemoryExporter $spanExporter;

    private TracerProvider $tracerProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousStorage = Context::storage();
    }

    protected function setUpInCoroutine(): void
    {
        Context::setStorage(new CoroutineContextStorage(Context::getRoot()));
        $this->events = new Dispatcher;
        $this->spanExporter = new InMemoryExporter(new ArrayObject);
        $this->tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($this->spanExporter))
            ->build();
    }

    protected function tearDownInCoroutine(): void
    {
        $this->tracerProvider->shutdown();
    }

    protected function tearDown(): void
    {
        Context::setStorage($this->previousStorage);

        parent::tearDown();
    }

    public function testEmptyAllowlistRegistersNoObserver(): void
    {
        $this->instrumentation()->register(['events' => []]);

        $this->assertSame([], $this->events->getObservers(EventInstrumentationTestEvent::class));
    }

    public function testRecordsOnlyExactAllowedEventNamesWithoutPayloadAttributes(): void
    {
        $this->instrumentation()->register([
            'events' => [EventInstrumentationTestEvent::class, 'billing.updated'],
        ]);

        $this->withinSpan(function (): void {
            $this->events->dispatch(new EventInstrumentationTestEvent('private payload'));
            $this->events->dispatch('billing.updated', ['private payload']);
            $this->events->dispatch('billing.deleted', ['private payload']);
        });

        $events = $this->exportedSpan()->getEvents();
        $this->assertCount(2, $events);
        $this->assertSame(EventInstrumentationTestEvent::class, $events[0]->getName());
        $this->assertSame([], $events[0]->getAttributes()->toArray());
        $this->assertSame('billing.updated', $events[1]->getName());
        $this->assertSame([], $events[1]->getAttributes()->toArray());
    }

    public function testObserverRunsAfterAnOrdinaryListenerFailureWithoutReplacingIt(): void
    {
        $exception = new RuntimeException('Listener failed.');
        $this->events->listen(EventInstrumentationTestEvent::class, static fn () => throw $exception);
        $this->instrumentation()->register(['events' => [EventInstrumentationTestEvent::class]]);

        $caught = null;
        $this->withinSpan(function () use (&$caught): void {
            try {
                $this->events->dispatch(new EventInstrumentationTestEvent('private payload'));
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }
        });

        $this->assertSame($exception, $caught);
        $spanEvents = $this->exportedSpan()->getEvents();
        $this->assertCount(1, $spanEvents);
        $this->assertSame(EventInstrumentationTestEvent::class, $spanEvents[0]->getName());
    }

    public function testPassiveObservationDoesNotMakeGuardedEventsDispatch(): void
    {
        $this->instrumentation()->register(['events' => [EventInstrumentationTestEvent::class]]);
        $constructed = false;

        $this->withinSpan(function () use (&$constructed): void {
            if ($this->events->hasListeners(EventInstrumentationTestEvent::class)) {
                $constructed = true;
                $this->events->dispatch(new EventInstrumentationTestEvent('private payload'));
            }
        });

        $this->assertFalse($constructed);
        $this->assertFalse($this->events->hasListeners(EventInstrumentationTestEvent::class));
        $this->assertSame([], $this->exportedSpan()->getEvents());
    }

    public function testNonRecordingCurrentSpanPerformsNoEventWork(): void
    {
        $this->instrumentation()->register(['events' => [EventInstrumentationTestEvent::class]]);

        $this->events->dispatch(new EventInstrumentationTestEvent('private payload'));

        $this->assertSame([], $this->spanExporter->getSpans());
    }

    /**
     * Create event instrumentation.
     */
    private function instrumentation(): EventInstrumentation
    {
        return new EventInstrumentation($this->events);
    }

    /**
     * Run a callback with one recording span active.
     */
    private function withinSpan(callable $callback): void
    {
        $span = $this->tracerProvider->getTracer('test')->spanBuilder('ambient')->startSpan();
        $scope = $span->activate();

        try {
            $callback();
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Return the exported ambient span.
     */
    private function exportedSpan(): SpanDataInterface
    {
        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);

        return $spans[0];
    }
}

class EventInstrumentationTestEvent
{
    /**
     * Create a test event.
     */
    public function __construct(public string $payload)
    {
    }
}
