<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Ai\Events\ClassificationFailed;
use Hypervel\Ai\Events\Classified;
use Hypervel\Ai\Events\Classifying;
use Hypervel\Sentry\Features\Ai\AiSpanDataBag;
use Hypervel\Sentry\Features\Ai\AiTracingScope;
use Hypervel\Sentry\Features\Ai\AiTracingState;
use Hypervel\Sentry\Features\Classification\ClassificationMessageFormatter;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use WeakReference;

/**
 * @internal
 */
class ClassificationIntegration extends Feature
{
    private const string FEATURE_KEY = 'gen_ai';

    private const string FEATURE_KEY_EVALUATE = 'gen_ai_evaluate';

    /**
     * Determine whether classification tracing is enabled and available.
     */
    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled(self::FEATURE_KEY)
            && $this->isTracingFeatureEnabled(self::FEATURE_KEY_EVALUATE)
            && class_exists(Classifying::class);
    }

    /**
     * Register classification lifecycle listeners.
     */
    public function onBoot(): void
    {
        $events = $this->container->make('events');

        foreach ([
            Classifying::class => 'handleClassifyingForTracing',
            Classified::class => 'handleClassifiedForTracing',
            ClassificationFailed::class => 'handleClassificationFailedForTracing',
        ] as $event => $method) {
            $events->listen($event, function (object $event) use ($method): void {
                $this->container->make(self::class)->{$method}($event);
            });
        }

        // ClassificationFailed identifies the invocation directly; upstream HTTP-host
        // and provider-failover matching is unnecessary, including for custom providers.
    }

    /**
     * Start a classification span under the current sampled scope.
     */
    public function handleClassifyingForTracing(Classifying $event): void
    {
        $sentryScope = AiTracingScope::sentryScope();
        $parentSpan = $sentryScope->getSpan();
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $data = new AiSpanDataBag([
            'gen_ai.operation.name' => 'evaluate',
            'gen_ai.request.model' => $event->model,
            'gen_ai.provider.name' => $event->provider->name(),
        ]);

        if ($this->shouldCollectGenAiInputs()) {
            $data->set('gen_ai.input.messages', ClassificationMessageFormatter::formatInputMessages($event->prompt->state, $event->prompt->questions));
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.evaluate')
                ->setData($data->toArray())
                ->setOrigin('auto.ai.hypervel')
                ->setDescription('evaluate ' . $event->model)
        );

        $scope = AiTracingScope::getOrCreate();
        $scope->conversation?->attach($span);

        $scope->state->classifications->set($event->invocationId, [
            'span' => $span,
            'owner' => WeakReference::create($scope),
        ]);

        $scope->push($span, $sentryScope);
    }

    /**
     * Record classification answers and finish the owned span.
     */
    public function handleClassifiedForTracing(Classified $event): void
    {
        $classification = AiTracingScope::current()?->state->classifications->pull($event->invocationId);

        if ($classification === null) {
            return;
        }

        $span = $classification['span'];
        $data = new AiSpanDataBag($span->getData());
        $data->set('gen_ai.response.model', $event->response->meta->model);
        $data->setTokenUsage($event->response->usage);

        if ($this->shouldCollectGenAiOutputs()) {
            $data->set('gen_ai.output.messages', ClassificationMessageFormatter::formatOutputMessages($event->response->answers));
        }

        $span->setData($data->toArray());
        AiTracingScope::finishSpan($span, $classification['owner']->get(), SpanStatus::ok());
    }

    /**
     * Close the exact failed classification attempt before failover or rethrowing.
     */
    public function handleClassificationFailedForTracing(ClassificationFailed $event): void
    {
        $classification = AiTracingScope::current()?->state->classifications->pull($event->invocationId);
        if ($classification === null) {
            return;
        }

        AiTracingScope::finishSpan($classification['span'], $classification['owner']->get(), AiTracingState::failureStatus($event->exception));
    }
}
