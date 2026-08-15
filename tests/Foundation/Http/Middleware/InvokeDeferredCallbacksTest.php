<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http\Middleware;

use Hypervel\Container\Container;
use Hypervel\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Hypervel\Http\Request;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

use function Hypervel\Support\defer;

class InvokeDeferredCallbacksTest extends TestCase
{
    public function testItInvokesDeferredCallbacks(): void
    {
        $container = Container::setInstance(new Container);
        $container->scoped(DeferredCallbackCollection::class);

        $ran = false;
        defer(function () use (&$ran) {
            $ran = true;
        });

        (new InvokeDeferredCallbacks)->terminate(new Request, new Response('', 200));

        $this->assertTrue($ran, 'defer() callback did not run through the middleware.');
    }

    public function testItSkipsDeferredCallbacksOnFailedResponses(): void
    {
        $container = Container::setInstance(new Container);
        $container->scoped(DeferredCallbackCollection::class);

        $ran = false;
        $always = false;

        defer(function () use (&$ran) {
            $ran = true;
        });
        defer(function () use (&$always) {
            $always = true;
        }, always: true);

        (new InvokeDeferredCallbacks)->terminate(new Request, new Response('', 500));

        $this->assertFalse($ran, 'A deferred callback ran despite the response failing.');
        $this->assertTrue($always, 'An always-deferred callback did not run on a failed response.');
    }

    public function testItDoesNotConstructTheCollectionWhenNothingDeferred(): void
    {
        $container = Container::setInstance(new Container);
        $container->scoped(DeferredCallbackCollection::class);

        (new InvokeDeferredCallbacks)->terminate(new Request, new Response('', 200));

        $this->assertFalse(
            $container->resolvedScoped(DeferredCallbackCollection::class),
            'The collection was constructed even though nothing deferred.'
        );
    }
}
