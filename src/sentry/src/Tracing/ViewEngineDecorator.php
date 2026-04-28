<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Tracing;

use Hypervel\Contracts\View\Engine;
use Hypervel\View\Factory;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

final class ViewEngineDecorator implements Engine
{
    public const SHARED_KEY = '__sentry_tracing_view_name';

    /**
     * Create a new view engine decorator instance.
     */
    public function __construct(
        private readonly Engine $engine,
        private readonly Factory $viewFactory,
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
                ->setDescription($this->viewFactory->shared(self::SHARED_KEY, basename($path)))
        );

        SentrySdk::getCurrentHub()->setSpan($span);

        $result = $this->engine->get($path, $data);

        $span->finish();

        SentrySdk::getCurrentHub()->setSpan($parentSpan);

        return $result;
    }

    /**
     * Proxy method calls to the underlying engine.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->engine->{$name}(...$arguments);
    }
}
