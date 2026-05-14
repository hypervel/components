<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Tracing;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\View\Engine;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

final class ViewEngineDecorator implements Engine
{
    public const CONTEXT_KEY = '__sentry.view_name';

    /**
     * Create a new view engine decorator instance.
     */
    public function __construct(
        private readonly Engine $engine,
    ) {
    }

    /**
     * Get the evaluated contents of the view.
     */
    public function get(string $path, array $data = []): string
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no sampled span there is no need to wrap the engine call
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return $this->engine->get($path, $data);
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('view.render')
                ->setOrigin('auto.view')
                ->setDescription(CoroutineContext::get(self::CONTEXT_KEY, basename($path)))
        );

        SentrySdk::getCurrentHub()->setSpan($span);

        try {
            return $this->engine->get($path, $data);
        } finally {
            $span->finish();

            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }

    /**
     * Proxy method calls to the underlying engine.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->engine->{$name}(...$arguments);
    }
}
