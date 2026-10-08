<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\Events\WorkerStopping;
use Hypervel\Queue\Queue;
use Hypervel\Queue\WorkerStopReason;
use Hypervel\Sentry\Features\Concerns\TracksPushedScopesAndSpans;
use Hypervel\Sentry\Integration;
use Hypervel\Support\Str;
use Sentry\Breadcrumb;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\KeyValueCollectionBehavior;
use Sentry\DataCollection\KeyValueDataFilter;
use Sentry\SentrySdk;
use Sentry\Serializer\Serializer;
use Sentry\State\Scope;
use Sentry\Tracing\PropagationContext;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;

use function Sentry\continueTrace;
use function Sentry\getBaggage;
use function Sentry\getTraceparent;

class QueueIntegration extends Feature
{
    use TracksPushedScopesAndSpans {
        pushScope as private pushScopeTrait;
    }

    private const string QUEUE_SPAN_OP_QUEUE_PUBLISH = 'queue.publish';

    private const string QUEUE_PAYLOAD_BAGGAGE_DATA = 'sentry_baggage_data';

    private const string QUEUE_PAYLOAD_TRACE_PARENT_DATA = 'sentry_trace_parent_data';

    private const string QUEUE_PAYLOAD_PUBLISH_TIME = 'sentry_publish_time';

    private const string QUEUE_PAYLOAD_DESTINATION_NAME = 'sentry_destination_name';

    private const string QUEUE_SPAN_DATA_ARGUMENTS = 'messaging.message.body.data';

    private const string JOB_FRAMES_CONTEXT_KEY = '__sentry.queue.job_frames';

    /**
     * Indicate if the feature is applicable to the current environment.
     */
    public function isApplicable(): bool
    {
        return $this->container->bound('queue');
    }

    /**
     * Set up the feature in the environment.
     */
    public function onBoot(): void
    {
        $dispatcher = $this->container->make('events');
        $recordSpans = $this->isTracingFeatureEnabled('queue_jobs')
            || $this->isTracingFeatureEnabled('queue_job_transactions');
        $recordBreadcrumbs = $this->isBreadcrumbFeatureEnabled('queue_info');

        if ($recordSpans) {
            $dispatcher->listen(JobQueueing::class, [$this, 'handleJobQueueingEvent']);
            $dispatcher->listen(JobQueued::class, [$this, 'handleJobQueuedEvent']);
            $dispatcher->listen(JobQueueingFailed::class, [$this, 'handleJobQueueingFailedEvent']);
        }

        if ($recordSpans || $recordBreadcrumbs) {
            $dispatcher->listen(JobProcessed::class, [$this, 'handleJobProcessedQueueEvent']);
            $dispatcher->listen(JobProcessing::class, [$this, 'handleJobProcessingQueueEvent']);
            $dispatcher->listen(JobFailed::class, [$this, 'handleJobFailedEvent']);
            $dispatcher->listen(JobAttempted::class, [$this, 'handleJobAttemptedEvent']);
        }

        $dispatcher->listen(WorkerStopping::class, [$this, 'handleWorkerStoppingQueueEvent']);
        $dispatcher->listen(JobExceptionOccurred::class, [$this, 'handleJobExceptionOccurredQueueEvent']);

        // Publish spans start on JobQueueing instead of here: the sync queue also creates
        // payloads but never raises JobQueued, so a span started here would never finish
        Queue::createPayloadUsing(function (?string $connection, ?string $queue, ?array $payload) use ($recordSpans): ?array {
            if ($payload !== null) {
                $payload[self::QUEUE_PAYLOAD_BAGGAGE_DATA] = getBaggage();
                $payload[self::QUEUE_PAYLOAD_TRACE_PARENT_DATA] = getTraceparent();
                $payload[self::QUEUE_PAYLOAD_PUBLISH_TIME] = microtime(true);

                if ($recordSpans) {
                    $payload[self::QUEUE_PAYLOAD_DESTINATION_NAME] = $queue;
                }
            }

            return $payload;
        });
    }

    /**
     * Start a publish span for the job being queued.
     */
    public function handleJobQueueingEvent(JobQueueing $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no tracing span active there is no need to handle the event
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $jobName = $event->job;

        if ($jobName instanceof Closure) {
            $jobName = 'Closure';
        } elseif (is_object($jobName)) {
            $jobName = get_class($jobName);
        }

        $payload = $event->payload();
        $destination = $payload[self::QUEUE_PAYLOAD_DESTINATION_NAME] ?? null;

        if (! is_string($destination)) {
            $destination = $event->queue;
        }

        $context = (new SpanContext)
            ->setOp(self::QUEUE_SPAN_OP_QUEUE_PUBLISH)
            ->setData(array_merge([
                'messaging.system' => 'hypervel',
                'messaging.message.id' => $payload['uuid'] ?? null,
                'messaging.destination.name' => $this->normalizeQueueName($destination),
                'messaging.destination.connection' => $event->connectionName,
            ], $this->collectPayloadArguments($payload)))
            ->setDescription($jobName);

        $this->trackLocalSpan($event->payload, $parentSpan->startChild($context));
    }

    /**
     * Finish the publish span of the queued job.
     */
    public function handleJobQueuedEvent(JobQueued $event): void
    {
        $this->maybeFinishLocalSpan($event->payload, SpanStatus::ok());
    }

    /**
     * Finish the publish span of the job that failed to queue.
     */
    public function handleJobQueueingFailedEvent(JobQueueingFailed $event): void
    {
        $this->maybeFinishLocalSpan($event->payload, SpanStatus::internalError());
    }

    /**
     * Finish the process span and pop the scope of the processed job.
     */
    public function handleJobProcessedQueueEvent(JobProcessed $event): void
    {
        $frames = CoroutineContext::get(self::JOB_FRAMES_CONTEXT_KEY, []);

        if ($this->findActiveJobFrame($frames, $event->job) === null) {
            return;
        }

        // A nested job that failed and was caught leaves its scope above this job's scope
        $frames = $this->discardEndedJobScopes($frames);
        $frame = array_pop($frames);
        CoroutineContext::set(self::JOB_FRAMES_CONTEXT_KEY, $frames);

        if ($frame['ownsSpan']) {
            $this->maybeFinishSpan(SpanStatus::ok());
        }

        $this->maybePopScope();
    }

    /**
     * Push a scope and start a process span or transaction for the job being processed.
     */
    public function handleJobProcessingQueueEvent(JobProcessing $event): void
    {
        $frames = $this->discardEndedJobScopes(CoroutineContext::get(self::JOB_FRAMES_CONTEXT_KEY, []));

        $this->pushScope();

        $frames[] = ['job' => $event->job, 'ownsSpan' => false, 'ended' => false];
        CoroutineContext::set(self::JOB_FRAMES_CONTEXT_KEY, $frames);

        if ($this->isBreadcrumbFeatureEnabled('queue_info')) {
            Integration::addBreadcrumb(new Breadcrumb(
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
            ));
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no tracing span active and we don't trace jobs as transactions there is no need to handle the event
        if ($parentSpan === null && ! $this->isTracingFeatureEnabled('queue_job_transactions')) {
            return;
        }

        // If there is a parent span we can record the job as a child unless the parent is not sampled or we are configured to not do so
        if ($parentSpan !== null && (! $parentSpan->getSampled() || ! $this->isTracingFeatureEnabled('queue_jobs'))) {
            return;
        }

        $jobPayload = $event->job->payload();

        if ($parentSpan === null) {
            $baggage = $jobPayload[self::QUEUE_PAYLOAD_BAGGAGE_DATA] ?? null;
            $traceParent = $jobPayload[self::QUEUE_PAYLOAD_TRACE_PARENT_DATA] ?? null;

            $context = continueTrace($traceParent ?? '', $baggage ?? '');

            // If the parent transaction was not sampled we also stop the queue job from being recorded
            if ($context->getParentSampled() === false) {
                return;
            }
        } else {
            $context = new SpanContext;
        }

        $resolvedJobName = $event->job->resolveName();

        $jobPublishedAt = $jobPayload[self::QUEUE_PAYLOAD_PUBLISH_TIME] ?? null;
        $jobData = json_encode($jobPayload['data'] ?? []);

        $job = [
            'messaging.system' => 'hypervel',

            'messaging.destination.name' => $this->normalizeQueueName($event->job->getQueue()),
            'messaging.destination.connection' => $event->connectionName,

            'messaging.message.id' => $jobPayload['uuid'] ?? null,
            'messaging.message.envelope.size' => strlen($event->job->getRawBody()),
            'messaging.message.body.size' => $jobData === false ? null : strlen($jobData),
            'messaging.message.retry.count' => $event->job->attempts() - 1,
            'messaging.message.receive.latency' => $jobPublishedAt !== null ? microtime(true) - $jobPublishedAt : null,
        ];

        if ($context instanceof TransactionContext) {
            $context->setName($resolvedJobName);
            $context->setSource(TransactionSource::task());
        }

        $context->setOp('queue.process');
        $context->setData(array_merge($job, $this->collectPayloadArguments($jobPayload)));
        $context->setOrigin('auto.queue');
        $context->setStartTimestamp(microtime(true));

        // When the parent span is null we start a new transaction otherwise we start a child of the current span
        if ($parentSpan === null) {
            $span = SentrySdk::getCurrentHub()->startTransaction($context);
        } else {
            $span = $parentSpan->startChild($context);
        }

        $this->pushSpan($span);

        $frames[array_key_last($frames)]['ownsSpan'] = true;
        CoroutineContext::set(self::JOB_FRAMES_CONTEXT_KEY, $frames);
    }

    /**
     * Handle a permanently failed job.
     *
     * Finishes the span with an error status but does not pop the scope —
     * breadcrumbs need to remain available for exception reporting.
     * Once the attempt has ended, the scope is discarded when the next job
     * starts or when the parent job completes.
     */
    public function handleJobFailedEvent(JobFailed $event): void
    {
        $this->finishFailedJobSpan($event->job);
    }

    /**
     * Mark the job attempt as ended.
     *
     * A failed job can keep running and dispatch other jobs, so a retained
     * scope is only discarded once its attempt has ended.
     */
    public function handleJobAttemptedEvent(JobAttempted $event): void
    {
        $frames = CoroutineContext::get(self::JOB_FRAMES_CONTEXT_KEY, []);
        $index = $this->findActiveJobFrame($frames, $event->job);

        if ($index === null) {
            return;
        }

        $frames[$index]['ended'] = true;
        CoroutineContext::set(self::JOB_FRAMES_CONTEXT_KEY, $frames);
    }

    /**
     * Drain pending events before the worker stops gracefully.
     */
    public function handleWorkerStoppingQueueEvent(WorkerStopping $event): void
    {
        if ($event->terminatesImmediately || $event->reason === WorkerStopReason::MaxMemoryExceeded) {
            return;
        }

        Integration::drainEvents();
    }

    /**
     * Finish the process span of the job that threw an exception.
     */
    public function handleJobExceptionOccurredQueueEvent(JobExceptionOccurred $event): void
    {
        $this->finishFailedJobSpan($event->job);
    }

    /**
     * Finish the span of the failed job attempt, keeping its scope for exception reporting.
     */
    private function finishFailedJobSpan(Job $job): void
    {
        $frames = CoroutineContext::get(self::JOB_FRAMES_CONTEXT_KEY, []);
        $index = $this->findActiveJobFrame($frames, $job);

        if ($index === null || ! $frames[$index]['ownsSpan']) {
            return;
        }

        $frames[$index]['ownsSpan'] = false;
        CoroutineContext::set(self::JOB_FRAMES_CONTEXT_KEY, $frames);

        $this->maybeFinishSpan(SpanStatus::internalError());
    }

    /**
     * Get the index of the job's frame if it is the latest job attempt that has not ended.
     *
     * Only that attempt can be running in this coroutine, so events for other jobs, such as
     * the attempted event of a nested job that already completed, are ignored.
     *
     * @param list<array{job: Job, ownsSpan: bool, ended: bool}> $frames
     */
    private function findActiveJobFrame(array $frames, Job $job): ?int
    {
        for ($index = count($frames) - 1; $index >= 0; --$index) {
            if (! $frames[$index]['ended']) {
                return $frames[$index]['job'] === $job ? $index : null;
            }
        }

        return null;
    }

    /**
     * Pop the scopes that ended job attempts retained after failing.
     *
     * @param list<array{job: Job, ownsSpan: bool, ended: bool}> $frames
     *
     * @return list<array{job: Job, ownsSpan: bool, ended: bool}>
     */
    private function discardEndedJobScopes(array $frames): array
    {
        if ($frames === [] || ! end($frames)['ended']) {
            return $frames;
        }

        $hub = SentrySdk::getCurrentHub();
        $currentSpan = $hub->getSpan();

        do {
            array_pop($frames);
            $this->maybePopScope();
        } while ($frames !== [] && end($frames)['ended']);

        // Spans that finished while a retained scope was on top only updated that scope, so carry the current span down
        $hub->setSpan($currentSpan);

        return $frames;
    }

    /**
     * Collect the arguments of a queued job that is not a serialized command.
     *
     * @param null|array<array-key, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function collectPayloadArguments(?array $payload): array
    {
        $data = $payload['data'] ?? null;

        if (! is_array($data) || $data === [] || isset($data['commandName'], $data['command'])) {
            return [];
        }

        $policy = DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub());
        $dataCollection = $policy->getDataCollection();

        // The legacy options never collected the arguments passed to queued jobs
        if ($dataCollection === null || ! $dataCollection->shouldCollectQueues()) {
            return [];
        }

        $filter = new KeyValueDataFilter(KeyValueCollectionBehavior::denyList());
        $filtered = $filter->filterKeyValueData($data, [new Serializer($policy->getOptions()), 'serialize']);

        return [
            self::QUEUE_SPAN_DATA_ARGUMENTS => json_encode($filtered) ?: KeyValueDataFilter::FILTERED_VALUE,
        ];
    }

    /**
     * Normalize the queue name to the name of its destination.
     */
    private function normalizeQueueName(?string $queue): string
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

    /**
     * Push a scope for the job with fresh breadcrumbs and propagation context.
     */
    protected function pushScope(): Scope
    {
        $scope = $this->pushScopeTrait();

        // When a job starts, we want to make sure the scope is cleared of breadcrumbs
        // as well as setting a new propagation context.
        $scope->clearBreadcrumbs();
        $scope->setPropagationContext(PropagationContext::fromDefaults());

        return $scope;
    }
}
