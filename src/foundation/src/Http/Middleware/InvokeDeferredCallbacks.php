<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Support\Defer\DeferredCallback;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Symfony\Component\HttpFoundation\Response;

class InvokeDeferredCallbacks
{
    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Invoke the deferred callbacks.
     */
    public function terminate(Request $request, Response $response): void
    {
        $container = Container::getInstance();

        // The collection is scoped, so it only exists here if something in this
        // request actually called defer(). Resolving it otherwise would build a
        // collection on every request just to iterate nothing.
        if (! $container->resolvedScoped(DeferredCallbackCollection::class)) {
            return;
        }

        $container->make(DeferredCallbackCollection::class)
            ->invokeWhen(fn (DeferredCallback $callback) => $response->getStatusCode() < 400 || $callback->always);
    }
}
