<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Integrations;

use Hyperf\Tracer\TracerContext;
use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\State\Scope;

class TraceIntegration implements IntegrationInterface
{
    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(function (Event $event): Event {
            if (class_exists(TracerContext::class) && $span = TracerContext::getRoot()) {
                /** @var \ZipkinOpenTracing\SpanContext $spanContext */
                $spanContext = $span->getContext();
                /** @var \Zipkin\Propagation\TraceContext $traceContext */
                $traceContext = $spanContext->getContext();
                $event->setContext('Hyperf Trace', [
                    'Trace ID' => $traceContext->getTraceId(),
                    'Parent ID' => $traceContext->getParentId(),
                    'Span ID' => $traceContext->getSpanId(),
                ]);
            }

            return $event;
        });
    }
}
