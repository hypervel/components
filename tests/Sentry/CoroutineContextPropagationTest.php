<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Sentry\Hub;
use Sentry\Event;
use Sentry\State\Layer;
use Sentry\State\Scope;
use Swoole\Coroutine\Channel;

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
}
