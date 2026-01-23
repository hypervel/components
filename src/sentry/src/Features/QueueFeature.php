<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Closure;
use Hypervel\Event\Contracts\Dispatcher;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\WorkerStopping;
use Hypervel\Queue\Queue;
use Hypervel\Sentry\Integrations\Integration;
use Hypervel\Sentry\Traits\TracksPushedScopesAndSpans;
use Hypervel\Support\Str;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\PropagationContext;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;

use function Sentry\continueTrace;
use function Sentry\getBaggage;
use function Sentry\getTraceparent;

class QueueFeature extends Feature
{
    use TracksPushedScopesAndSpans {
        pushScope as private pushScopeTrait;
    }

    protected const QUEUE_SPAN_OP_QUEUE_PUBLISH = 'queue.publish';

    protected const QUEUE_SPAN_OP_QUEUE_PROCESS = 'queue.process';

    protected const QUEUE_PAYLOAD_BAGGAGE_DATA = 'sentry_baggage_data';

    protected const QUEUE_PAYLOAD_TRACE_PARENT_DATA = 'sentry_trace_parent_data';

    protected const QUEUE_PAYLOAD_PUBLISH_TIME = 'sentry_publish_time';

    protected const QUEUE_BREADCRUMB_FEATURE_KEY = 'queue_info';

    protected const QUEUE_TRACING_JOBS_FEATURE_KEY = 'queue_jobs';

    protected const QUEUE_TRACING_JOB_TRANSACTIONS_FEATURE_KEY = 'queue_job_transactions';

    public function isApplicable(): bool
    {
        if (! $this->container->bound('queue')) {
            return false;
        }

        return $this->switcher->isBreadcrumbEnable(static::QUEUE_BREADCRUMB_FEATURE_KEY)
            || $this->switcher->isTracingEnable(static::QUEUE_TRACING_JOBS_FEATURE_KEY)
            || $this->switcher->isTracingEnable(static::QUEUE_TRACING_JOB_TRANSACTIONS_FEATURE_KEY);
    }

    public function onBoot(): void
    {
        $dispatcher = $this->container->get(Dispatcher::class);
        $dispatcher->listen(JobQueueing::class, [$this, 'handleJobQueueingEvent']);
        $dispatcher->listen(JobQueued::class, [$this, 'handleJobQueuedEvent']);

        $dispatcher->listen(JobProcessed::class, [$this, 'handleJobProcessedQueueEvent']);
        $dispatcher->listen(JobProcessing::class, [$this, 'handleJobProcessingQueueEvent']);
        $dispatcher->listen(JobFailed::class, [$this, 'handleJobFailedEvent']);
        $dispatcher->listen(WorkerStopping::class, [$this, 'handleWorkerStoppingQueueEvent']);
        $dispatcher->listen(JobExceptionOccurred::class, [$this, 'handleJobExceptionOccurredQueueEvent']);

        if ($this->switcher->isTracingEnable(static::QUEUE_TRACING_JOBS_FEATURE_KEY)
            || $this->switcher->isTracingEnable(static::QUEUE_TRACING_JOB_TRANSACTIONS_FEATURE_KEY)) {
            Queue::createPayloadUsing(function (?string $connection, ?string $queue, ?array $payload): ?array {
                $parentSpan = SentrySdk::getCurrentHub()->getSpan();

                if ($parentSpan !== null && $parentSpan->getSampled()) {
                    $context = SpanContext::make()
                        ->setOp(static::QUEUE_SPAN_OP_QUEUE_PUBLISH)
                        ->setData([
                            'messaging.system' => 'hypervel',
                            'messaging.message.id' => $payload['uuid'] ?? null,
                            'messaging.destination.name' => $this->normalizeQueueName($queue),
                            'messaging.destination.connection' => $connection,
                        ])
                        ->setDescription($queue);

                    $this->pushSpan($parentSpan->startChild($context));
                }

                if ($payload !== null) {
                    $payload[static::QUEUE_PAYLOAD_BAGGAGE_DATA] = getBaggage();
                    $payload[static::QUEUE_PAYLOAD_TRACE_PARENT_DATA] = getTraceparent();
                    $payload[static::QUEUE_PAYLOAD_PUBLISH_TIME] = microtime(true);
                }

                return $payload;
            });
        }
    }

    public function handleJobQueueingEvent(JobQueueing $event): void
    {
        $currentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no tracing span active there is no need to handle the event
        if ($currentSpan === null || $currentSpan->getOp() !== static::QUEUE_SPAN_OP_QUEUE_PUBLISH) {
            return;
        }

        $jobName = $event->job;

        if ($jobName instanceof Closure) {
            $jobName = 'Closure';
        } elseif (is_object($jobName)) {
            $jobName = get_class($jobName);
        }

        $currentSpan
            ->setDescription($jobName);
    }

    public function handleJobQueuedEvent(JobQueued $event): void
    {
        $this->maybeFinishSpan();
    }

    public function handleJobProcessedQueueEvent(JobProcessed $event): void
    {
        $this->maybeFinishSpan(SpanStatus::ok());

        $this->maybePopScope();
    }

    public function handleJobProcessingQueueEvent(JobProcessing $event): void
    {
        $this->maybePopScope();

        $this->pushScope();

        if ($this->switcher->isBreadcrumbEnable(static::QUEUE_BREADCRUMB_FEATURE_KEY)) {
            Integration::addBreadcrumb(
                new Breadcrumb(
                    Breadcrumb::LEVEL_INFO,
                    Breadcrumb::TYPE_DEFAULT,
                    'queue.job',
                    'Processing queue job',
                    [
                        'job' => $event->job->getName(),
                        'queue' => $event->job->getQueue(),
                        'attempts' => $event->job->attempts(),
                        'connection' => $event->connectionName,
                        'resolved' => $event->job->resolveName(),
                    ]
                )
            );
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no tracing span active and we don't trace jobs as transactions there is no need to handle the event
        if ($parentSpan === null && ! $this->switcher->isTracingEnable(
            static::QUEUE_TRACING_JOB_TRANSACTIONS_FEATURE_KEY
        )) {
            return;
        }

        // If there is a parent span we can record the job as a child unless the parent is not sample or we are configured to not do so
        if ($parentSpan !== null && (! $parentSpan->getSampled() || ! $this->switcher->isTracingEnable(
            static::QUEUE_TRACING_JOBS_FEATURE_KEY
        ))) {
            return;
        }

        $jobPayload = json_decode($event->job->getRawBody(), true);
        $jobPublishedAt = $jobPayload[static::QUEUE_PAYLOAD_PUBLISH_TIME] ?? null;
        $job = [
            'messaging.system' => 'hypervel',
            'messaging.destination.name' => $this->normalizeQueueName($event->job->getQueue()),
            'messaging.destination.connection' => $event->connectionName,

            'messaging.message.id' => $jobPayload['uuid'] ?? null,
            'messaging.message.envelope.size' => strlen($event->job->getRawBody()),
            'messaging.message.body.size' => strlen(json_encode($jobPayload['data'] ?? [])),
            'messaging.message.retry.count' => $event->job->attempts() - 1,
            'messaging.message.receive.latency' => $jobPublishedAt !== null ? microtime(true) - $jobPublishedAt : null,
        ];

        if ($parentSpan === null) {
            $baggage = $jobPayload[static::QUEUE_PAYLOAD_BAGGAGE_DATA] ?? null;
            $traceParent = $jobPayload[static::QUEUE_PAYLOAD_TRACE_PARENT_DATA] ?? null;

            $context = continueTrace($traceParent ?? '', $baggage ?? '');

            // If the parent transaction was not sampled we also stop the queue job from being recorded
            if ($context->getParentSampled() === false) {
                return;
            }

            $context->setName($event->job->resolveName());
            $context->setSource(TransactionSource::task());
        } else {
            $context = SpanContext::make();
        }

        $context->setOp(static::QUEUE_SPAN_OP_QUEUE_PROCESS);
        $context->setData($job);
        $context->setOrigin('auto.queue');
        $context->setStartTimestamp(microtime(true));

        // When the parent span is null we start a new transaction otherwise we start a child of the current span
        if ($parentSpan === null && $context instanceof TransactionContext) {
            $span = SentrySdk::getCurrentHub()->startTransaction($context);
        } else {
            $span = $parentSpan->startChild($context);
        }

        $this->pushSpan($span);
    }

    public function handleJobFailedEvent(JobFailed $event): void
    {
        $this->maybeFinishSpan(SpanStatus::internalError());

        // Don't pop scope here - breadcrumbs need to remain available for exception
        // reporting. The next JobProcessing event will clean up via its maybePopScope()
        // call before pushing a new scope.
    }

    public function handleWorkerStoppingQueueEvent(WorkerStopping $event): void
    {
        Integration::flushEvents();
    }

    public function handleJobExceptionOccurredQueueEvent(JobExceptionOccurred $event): void
    {
        $this->maybeFinishSpan(SpanStatus::internalError());

        Integration::flushEvents();
    }

    protected function normalizeQueueName(?string $queue): string
    {
        if ($queue === null) {
            return '';
        }

        // SQS queues are sometimes formatted like: https://sqs.<region>.amazonaws.com/<id>/<queue_name>
        if (filter_var($queue, FILTER_VALIDATE_URL) !== false) {
            return Str::afterLast($queue, '/');
        }

        // Jobs pushed onto the Redis driver are formatted as queues:<queue>
        return Str::after($queue, 'queues:');
    }

    protected function getJobName(mixed $job): string
    {
        if ($job instanceof Closure) {
            return 'Closure';
        }

        if (is_object($job)) {
            return $job::class;
        }

        if (is_string($job)) {
            return $job;
        }

        return 'Unknown Job';
    }

    protected function pushScope(): void
    {
        $this->pushScopeTrait();

        // When a job starts, we want to make sure the scope is cleared of breadcrumbs
        // as well as setting a new propagation context.
        SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope) {
            $scope->clearBreadcrumbs();
            $scope->setPropagationContext(PropagationContext::fromDefaults());
        });
    }
}
