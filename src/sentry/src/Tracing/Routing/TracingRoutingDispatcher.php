<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Tracing\Routing;

use Hypervel\Routing\Route;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

abstract class TracingRoutingDispatcher
{
    /**
     * Wrap the route dispatch in a tracing span.
     */
    protected function wrapRouteDispatch(callable $dispatch, Route $route): mixed
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no sampled span there is no need to wrap the dispatch
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return $dispatch();
        }

        $action = $route->getActionName();

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('http.route')
                ->setOrigin('auto.http.server')
                ->setDescription($action)
        );

        SentrySdk::getCurrentHub()->setSpan($span);

        try {
            return $dispatch();
        } finally {
            $span->finish();

            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }
}
