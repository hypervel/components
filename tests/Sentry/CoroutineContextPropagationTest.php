<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Sentry\Hub;
use Hypervel\Sentry\State\CoroutineRuntimeContextStorage;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Sentry\Event;
use Sentry\EventType;
use Sentry\SentrySdk;
use Sentry\State\Layer;
use Sentry\State\Scope;
use Swoole\Coroutine\Channel;

use function Sentry\logger;
use function Sentry\traceMetrics;

class CoroutineContextPropagationTest extends SentryTestCase
{
    public function testOrdinaryChildCoroutinesCloneParentSentryState(): void
    {
        $hub = $this->getSentryHubFromContainer();
        $hub->pushScope();
        $hub->configureScope(static function (Scope $scope): void {
            $scope->setTag('owner', 'parent');
        });
        $span = $this->startTransaction();
        $request = Request::create('/test?owner=parent');
        CoroutineContext::set(Request::class, $request);

        /** @var list<Layer> $parentStack */
        $parentStack = CoroutineContext::get(Hub::CONTEXT_STACK_KEY);
        $results = new Channel(2);

        Coroutine::create(function () use ($hub, $results): void {
            /** @var list<Layer> $stack */
            $stack = CoroutineContext::get(Hub::CONTEXT_STACK_KEY);
            /** @var Request $request */
            $request = CoroutineContext::get(Request::class);
            $hub->configureScope(static function (Scope $scope): void {
                $scope->setTag('child', 'first');
            });
            $request->query->set('owner', 'first');

            $results->push([$stack, $request, $this->scopeTags($hub)]);
        });

        Coroutine::create(function () use ($hub, $results): void {
            $results->push([
                CoroutineContext::get(Hub::CONTEXT_STACK_KEY),
                CoroutineContext::get(Request::class),
                $this->scopeTags($hub),
            ]);
        });

        [$firstStack, $firstRequest, $firstTags] = $results->pop(1.0);
        [$secondStack, $secondRequest, $secondTags] = $results->pop(1.0);

        foreach ([$firstStack, $secondStack] as $childStack) {
            $this->assertNotSame($parentStack, $childStack);
            $this->assertCount(count($parentStack), $childStack);

            foreach ($childStack as $index => $layer) {
                $this->assertNotSame($parentStack[$index], $layer);
                $this->assertNotSame($parentStack[$index]->getScope(), $layer->getScope());
                $this->assertSame($parentStack[$index]->getClient(), $layer->getClient());
            }

            $this->assertSame($span, end($childStack)->getScope()->getSpan());
        }

        $this->assertNotSame($request, $firstRequest);
        $this->assertNotSame($request, $secondRequest);
        $this->assertNotSame($firstRequest, $secondRequest);
        $this->assertSame('first', $firstRequest->query('owner'));
        $this->assertSame('parent', $secondRequest->query('owner'));
        $this->assertSame('parent', $request->query('owner'));
        $this->assertSame(['owner' => 'parent', 'child' => 'first'], $firstTags);
        $this->assertSame(['owner' => 'parent'], $secondTags);
        $this->assertSame(['owner' => 'parent'], $this->scopeTags($hub));
    }

    public function testForkClonesTheInstalledContextSnapshot(): void
    {
        $hub = $this->getSentryHubFromContainer();
        $hub->pushScope();
        $request = Request::create('/fork');
        CoroutineContext::set(Request::class, $request);
        /** @var list<Layer> $parentStack */
        $parentStack = CoroutineContext::get(Hub::CONTEXT_STACK_KEY);
        $result = new Channel(1);

        Coroutine::fork(static function () use ($result): void {
            $result->push([
                CoroutineContext::get(Hub::CONTEXT_STACK_KEY),
                CoroutineContext::get(Request::class),
            ]);
        });

        [$childStack, $childRequest] = $result->pop(1.0);

        $this->assertNotSame($parentStack, $childStack);
        $this->assertNotSame($parentStack[0], $childStack[0]);
        $this->assertNotSame($parentStack[0]->getScope(), $childStack[0]->getScope());
        $this->assertNotSame($request, $childRequest);
    }

    public function testSelectiveForkStillPropagatesSentryInfrastructureContext(): void
    {
        $hub = $this->getSentryHubFromContainer();
        $hub->pushScope();
        $request = Request::create('/selective');
        CoroutineContext::set(Request::class, $request);
        CoroutineContext::set('selected', 'value');
        $result = new Channel(1);

        Coroutine::fork(static function () use ($result): void {
            $result->push([
                CoroutineContext::get(Hub::CONTEXT_STACK_KEY),
                CoroutineContext::get(Request::class),
                CoroutineContext::get('selected'),
            ]);
        }, ['selected']);

        [$childStack, $childRequest, $selected] = $result->pop(1.0);

        $this->assertNotNull($childStack);
        $this->assertNotSame($request, $childRequest);
        $this->assertSame('value', $selected);
    }

    public function testChildWithoutParentRequestContextGetsNull(): void
    {
        CoroutineContext::forget(Request::class);
        $result = new Channel(1);

        Coroutine::create(static function () use ($result): void {
            $result->push(CoroutineContext::get(Request::class) ?? 'missing');
        });

        $this->assertSame('missing', $result->pop(1.0));
    }

    public function testCreateSharesTheParentRuntimeContext(): void
    {
        $this->assertChildSharesActiveRuntimeContext(
            static fn (callable $callback): int => Coroutine::create($callback),
        );
    }

    public function testForkSharesTheParentRuntimeContext(): void
    {
        $this->assertChildSharesActiveRuntimeContext(
            static fn (callable $callback): int => Coroutine::fork($callback),
        );
    }

    public function testSelectiveForkSharesTheParentRuntimeContext(): void
    {
        $this->assertChildSharesActiveRuntimeContext(
            static fn (callable $callback): int => Coroutine::fork($callback, ['selected']),
        );
    }

    public function testGrandchildRetainsRuntimeContextAfterIntermediateParentExits(): void
    {
        SentrySdk::startContext($this->getSentryHubFromContainer());
        $runtimeContext = SentrySdk::getCurrentRuntimeContext();
        $grandchildReady = new Channel(1);
        $releaseGrandchild = new Channel(1);
        $grandchildResult = new Channel(1);
        $grandchildId = new Channel(1);

        $childId = Coroutine::create(static function () use (
            $grandchildReady,
            $releaseGrandchild,
            $grandchildResult,
            $grandchildId,
        ): void {
            $grandchildId->push(Coroutine::create(static function () use (
                $grandchildReady,
                $releaseGrandchild,
                $grandchildResult,
            ): void {
                $grandchildReady->push(true);
                $releaseGrandchild->pop();
                $grandchildResult->push(SentrySdk::getCurrentRuntimeContext());
            }));
        });

        $this->assertTrue($grandchildReady->pop(1.0));
        Coroutine::join([$childId]);

        $releaseGrandchild->push(true);

        $this->assertSame($runtimeContext, $grandchildResult->pop(1.0));
        Coroutine::join([$grandchildId->pop(1.0)]);

        SentrySdk::endContext();
    }

    public function testSharedTelemetryFlushesOnceWhenChildExitsFirst(): void
    {
        SentrySdk::startContext($this->getSentryHubFromContainer());
        logger()->info('parent log');
        traceMetrics()->count('parent.metric', 1);

        $childId = Coroutine::create(static function (): void {
            logger()->info('child log');
            traceMetrics()->count('child.metric', 1);
        });

        Coroutine::join([$childId]);
        $this->assertSame(0, $this->countCapturedEvents(EventType::logs()));
        $this->assertSame(0, $this->countCapturedEvents(EventType::metrics()));

        SentrySdk::endContext();

        $this->assertSharedTelemetryFlushedOnce();
    }

    public function testSharedTelemetryFlushesOnceWhenParentExitsFirst(): void
    {
        SentrySdk::startContext($this->getSentryHubFromContainer());
        logger()->info('parent log');
        traceMetrics()->count('parent.metric', 1);
        $childReady = new Channel(1);
        $releaseChild = new Channel(1);

        $childId = Coroutine::fork(static function () use ($childReady, $releaseChild): void {
            logger()->info('child log');
            traceMetrics()->count('child.metric', 1);
            $childReady->push(true);
            $releaseChild->pop();
        });

        $this->assertTrue($childReady->pop(1.0));

        SentrySdk::endContext();

        $this->assertSame(0, $this->countCapturedEvents(EventType::logs()));
        $this->assertSame(0, $this->countCapturedEvents(EventType::metrics()));

        $releaseChild->push(true);
        Coroutine::join([$childId]);

        $this->assertSharedTelemetryFlushedOnce();
    }

    public function testDuplicatePropagationHooksRetainChildOnce(): void
    {
        $this->resetApplicationWithConfig([]);
        SentrySdk::startContext($this->getSentryHubFromContainer());
        logger()->info('shared log');
        traceMetrics()->count('shared.metric', 1);

        $childId = Coroutine::create(static function (): void {
        });

        Coroutine::join([$childId]);

        SentrySdk::endContext();

        $this->assertSame(1, $this->countCapturedEvents(EventType::logs()));
        $this->assertSame(1, $this->countCapturedEvents(EventType::metrics()));
    }

    public function testDeliveryChildDoesNotInheritApplicationContext(): void
    {
        $hub = $this->getSentryHubFromContainer();
        $hub->pushScope();
        CoroutineContext::set(Request::class, Request::create('/delivery'));
        SentrySdk::startContext($hub);
        $result = new Channel(1);

        $childId = Coroutine::createOwned(
            static function () use ($result): void {
                $result->push([
                    CoroutineContext::get(Hub::CONTEXT_STACK_KEY),
                    CoroutineContext::get(Request::class),
                    app(CoroutineRuntimeContextStorage::class)->get(),
                ]);
            },
            static function (Closure $run): void {
                CoroutineContext::set(HttpPoolTransport::DELIVERY_CONTEXT_KEY, true);
                $run();
            },
        );

        [$stack, $request, $runtimeContext] = $result->pop(1.0);

        $this->assertNull($stack);
        $this->assertNull($request);
        $this->assertNull($runtimeContext);
        Coroutine::join([$childId]);

        SentrySdk::endContext();
    }

    /**
     * Get the tags applied by the current Hub scope.
     *
     * @return array<string, string>
     */
    private function scopeTags(Hub $hub): array
    {
        $event = Event::createEvent();
        $hub->configureScope(static function (Scope $scope) use (&$event): void {
            $event = $scope->applyToEvent($event);
        });

        return $event->getTags();
    }

    /**
     * Assert a child creation API shares the active runtime context.
     *
     * @param callable(callable(): void): int $createChild
     */
    private function assertChildSharesActiveRuntimeContext(callable $createChild): void
    {
        SentrySdk::startContext($this->getSentryHubFromContainer());
        $runtimeContext = SentrySdk::getCurrentRuntimeContext();
        $result = new Channel(1);

        $childId = $createChild(static function () use ($result): void {
            $result->push(SentrySdk::getCurrentRuntimeContext());
        });

        $this->assertSame($runtimeContext, $result->pop(1.0));
        Coroutine::join([$childId]);

        SentrySdk::endContext();
    }

    /**
     * Assert the shared telemetry buffers produced one event each.
     */
    private function assertSharedTelemetryFlushedOnce(): void
    {
        $logEvents = $this->getCapturedSentryEventsOfType(EventType::logs());
        $metricEvents = $this->getCapturedSentryEventsOfType(EventType::metrics());

        $this->assertCount(1, $logEvents);
        $this->assertCount(2, $logEvents[0][0]->getLogs());
        $this->assertCount(1, $metricEvents);
        $this->assertCount(2, $metricEvents[0][0]->getMetrics());
    }

    /**
     * Count captured Sentry events of a type.
     */
    private function countCapturedEvents(EventType $eventType): int
    {
        return count($this->getCapturedSentryEventsOfType($eventType));
    }
}
