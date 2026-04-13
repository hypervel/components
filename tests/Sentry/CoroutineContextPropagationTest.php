<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Sentry\Hub;
use Sentry\State\Scope;

/**
 * @internal
 * @coversNothing
 */
class CoroutineContextPropagationTest extends SentryTestCase
{
    public function testChildCoroutineInheritsSentryStackFromParent()
    {
        // Set up a Sentry scope stack in the parent coroutine
        $hub = $this->getSentryHubFromContainer();
        $hub->pushScope();
        $hub->configureScope(function (Scope $scope) {
            $scope->setTag('test_tag', 'parent_value');
        });

        $parentStack = CoroutineContext::get(Hub::CONTEXT_STACK_KEY);
        $this->assertNotNull($parentStack);

        $childStack = null;

        $channel = new \Swoole\Coroutine\Channel(1);

        Coroutine::create(function () use (&$childStack, $channel) {
            $childStack = CoroutineContext::get(Hub::CONTEXT_STACK_KEY);
            $channel->push(true);
        });

        $channel->pop(1.0);

        $this->assertNotNull($childStack, 'Child coroutine should inherit the Sentry scope stack from parent');
        $this->assertSame($parentStack, $childStack, 'Child coroutine should have the same scope stack reference as parent');
    }

    public function testChildCoroutineInheritsRequestContextFromParent()
    {
        // Set up a request in the parent coroutine context
        $request = Request::create('/test', 'GET');
        CoroutineContext::set(Request::class, $request);

        $childRequest = null;

        $channel = new \Swoole\Coroutine\Channel(1);

        Coroutine::create(function () use (&$childRequest, $channel) {
            $childRequest = CoroutineContext::get(Request::class);
            $channel->push(true);
        });

        $channel->pop(1.0);

        $this->assertNotNull($childRequest, 'Child coroutine should inherit the Request context from parent');
        $this->assertSame($request, $childRequest, 'Child coroutine should have the same Request instance as parent');
    }

    public function testChildCoroutineInheritsBothSentryStackAndRequest()
    {
        // Set up both Sentry stack and request context
        $hub = $this->getSentryHubFromContainer();
        $hub->pushScope();

        $request = Request::create('/both-test', 'POST');
        CoroutineContext::set(Request::class, $request);

        $parentStack = CoroutineContext::get(Hub::CONTEXT_STACK_KEY);

        $childStack = null;
        $childRequest = null;

        $channel = new \Swoole\Coroutine\Channel(1);

        Coroutine::create(function () use (&$childStack, &$childRequest, $channel) {
            $childStack = CoroutineContext::get(Hub::CONTEXT_STACK_KEY);
            $childRequest = CoroutineContext::get(Request::class);
            $channel->push(true);
        });

        $channel->pop(1.0);

        $this->assertSame($parentStack, $childStack);
        $this->assertSame($request, $childRequest);
    }

    public function testChildCoroutineWithoutParentContextGetsNull()
    {
        // Ensure no request is set in the parent
        CoroutineContext::forget(Request::class);

        $childRequest = 'sentinel';

        $channel = new \Swoole\Coroutine\Channel(1);

        Coroutine::create(function () use (&$childRequest, $channel) {
            $childRequest = CoroutineContext::get(Request::class);
            $channel->push(true);
        });

        $channel->pop(1.0);

        $this->assertNull($childRequest, 'Child coroutine should not have Request context when parent has none');
    }
}
